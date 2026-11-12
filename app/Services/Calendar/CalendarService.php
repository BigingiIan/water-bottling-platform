<?php

namespace App\Services\Calendar;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PublicHoliday;
use App\Models\SchoolTerm;
use App\Models\User;
use App\Support\ModuleAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits and deletes holidays, school terms and promotions, then rebuilds the forecast features
 * for exactly the dates the change touched.
 *
 * Touching a day the model has already been fed (sales_data_covered) changes history, so it needs full
 * access to the calendar, a reason, and is recorded in the audit log with that reason.
 */
class CalendarService
{
    public function __construct(
        private ContextCalendar $calendar,
        private ModuleAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function saveHoliday(User $actor, ?PublicHoliday $holiday, array $input, ?string $reason = null): PublicHoliday
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d'],
            'recurring' => ['required', 'boolean'],
        ], [], ['date' => 'date'])->validate();

        $recurring = filter_var($validated['recurring'], FILTER_VALIDATE_BOOLEAN);
        $attributes = [
            'name' => trim($validated['name']),
            'recurring' => $recurring,
            // Recurring holidays keep only their month and day, on the placeholder year.
            'date' => $recurring ? PublicHoliday::RECURRING_YEAR.substr($validated['date'], 4) : $validated['date'],
        ];
        $before = $holiday === null ? [] : $this->holidayDates($holiday);
        $after = $this->holidayDates(new PublicHoliday($attributes));

        return $this->apply($actor, 'holiday', $holiday ?? new PublicHoliday, $attributes, [...$before, ...$after], null, $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function saveSchoolTerm(User $actor, ?SchoolTerm $term, array $input, ?string $reason = null): SchoolTerm
    {
        $validated = Validator::make($input, [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'term' => ['required', 'integer', 'between:1,3', Rule::unique('school_terms', 'term')->where('year', $input['year'] ?? null)->ignore($term?->id)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [
            'term.unique' => 'That term is already recorded for this year.',
        ])->after(function ($validator) use ($input, $term): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ((int) substr((string) $input['start_date'], 0, 4) !== (int) $input['year']) {
                $validator->errors()->add('start_date', 'The term must start in the year it belongs to.');
            }

            $overlaps = SchoolTerm::query()
                ->when($term !== null, fn ($query) => $query->whereKeyNot($term->id))
                ->whereDate('start_date', '<=', $input['end_date'])
                ->whereDate('end_date', '>=', $input['start_date'])
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('start_date', 'These dates overlap another recorded term.');
            }
        })->validate();

        $attributes = [
            'year' => (int) $validated['year'],
            'term' => (int) $validated['term'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
        ];
        $before = $term === null ? [] : $this->rangeDates($term->start_date->toDateString(), $term->end_date->toDateString());

        return $this->apply($actor, 'school_term', $term ?? new SchoolTerm, $attributes, [...$before, ...$this->rangeDates($attributes['start_date'], $attributes['end_date'])], null, $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function savePromotion(User $actor, ?Promotion $promotion, array $input, ?string $reason = null): Promotion
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'type' => ['required', Rule::in(Promotion::TYPES)],
            'discount_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $attributes = [
            'name' => trim($validated['name']),
            'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'type' => $validated['type'],
            'discount_percent' => $validated['discount_percent'] ?? null,
            'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
        ];

        if ($promotion === null) {
            $attributes['created_by'] = $actor->id;
        }

        $before = $promotion === null ? [] : $this->rangeDates($promotion->start_date->toDateString(), $promotion->end_date->toDateString());
        $productIds = array_values(array_unique(array_filter([$promotion?->product_id, $attributes['product_id']], fn (?int $id): bool => $id !== null)));
        // Either version applying to every product means every product's flags can change.
        $scope = ($promotion !== null && $promotion->product_id === null) || $attributes['product_id'] === null ? null : $productIds;

        return $this->apply($actor, 'promotion', $promotion ?? new Promotion, $attributes, [...$before, ...$this->rangeDates($attributes['start_date'], $attributes['end_date'])], $scope, $reason);
    }

    /**
     * @throws ValidationException
     */
    public function delete(User $actor, PublicHoliday|SchoolTerm|Promotion $record, ?string $reason = null): void
    {
        [$entity, $dates, $scope] = match (true) {
            $record instanceof PublicHoliday => ['holiday', $this->holidayDates($record), null],
            $record instanceof SchoolTerm => ['school_term', $this->rangeDates($record->start_date->toDateString(), $record->end_date->toDateString()), null],
            $record instanceof Promotion => ['promotion', $this->rangeDates($record->start_date->toDateString(), $record->end_date->toDateString()), $record->product_id === null ? null : [$record->product_id]],
        };

        $this->apply($actor, $entity, $record, null, $dates, $scope, $reason);
    }

    /**
     * Whether saving or deleting with these dates would change days the model has already seen.
     *
     * @param  list<string>  $dates
     * @param  list<int>|null  $productIds
     */
    public function historyDayCount(array $dates, ?array $productIds = null): int
    {
        $skus = $productIds === null ? null : Product::query()->whereIn('id', $productIds)->pluck('unit_type')->all();

        return $this->calendar->coveredDayCount($dates, $skus);
    }

    /**
     * The dates on record a holiday falls on: its date, or for a recurring holiday that month and day in
     * every year that has feature rows.
     *
     * @return list<string>
     */
    public function holidayDates(PublicHoliday $holiday): array
    {
        if (! $holiday->recurring) {
            return [$holiday->date->toDateString()];
        }

        $bounds = DB::table('daily_sku_features')->selectRaw('MIN(date) as first_date, MAX(date) as last_date')->first();
        $firstYear = $bounds?->first_date ? (int) substr((string) $bounds->first_date, 0, 4) : (int) today()->format('Y');
        $lastYear = $bounds?->last_date ? (int) substr((string) $bounds->last_date, 0, 4) : (int) today()->format('Y');
        $dates = [];

        foreach (range($firstYear, max($firstYear, $lastYear)) as $year) {
            // 29 February only exists in leap years.
            if (checkdate((int) $holiday->date->format('n'), (int) $holiday->date->format('j'), $year)) {
                $dates[] = sprintf('%d-%s', $year, $holiday->date->format('m-d'));
            }
        }

        return $dates;
    }

    /**
     * @param  array<string, mixed>|null  $attributes  Null deletes the record.
     * @param  list<string>  $dates
     * @param  list<int>|null  $productIds
     *
     * @throws ValidationException
     */
    private function apply(User $actor, string $entity, Model $record, ?array $attributes, array $dates, ?array $productIds, ?string $reason): Model
    {
        $this->access->authorize($actor, 'calendar', write: true);

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $historyDays = $this->historyDayCount($dates, $productIds);

        if ($historyDays > 0 && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => "This changes {$historyDays} ".str('day')->plural($historyDays).' of sales history the forecast has already learned from. Give a reason to continue.',
            ]);
        }

        if ($reason !== null && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason under 500 characters.']);
        }

        return DB::transaction(function () use ($actor, $entity, $record, $attributes, $dates, $productIds, $reason, $historyDays): Model {
            $isNew = ! $record->exists;
            $oldValues = $isNew ? null : $this->auditValues($record);

            if ($attributes === null) {
                $record->delete();
                $action = 'deleted';
            } else {
                $record->fill($attributes)->save();
                $action = $isNew ? 'created' : 'updated';
            }

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => $historyDays > 0 ? 'calendar.history_changed' : "calendar.{$entity}_{$action}",
                'table_name' => $record->getTable(),
                'old_values' => $oldValues,
                'new_values' => [
                    ...($attributes === null ? [] : $this->auditValues($record->refresh())),
                    'change' => "{$entity}_{$action}",
                    'history_days' => $historyDays,
                    'reason' => $reason,
                ],
            ]);

            $this->calendar->rebuildDates($dates, includeCovered: $historyDays > 0, productIds: $productIds);

            return $record;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Model $record): array
    {
        return collect($record->attributesToArray())
            ->except(['created_at', 'updated_at'])
            ->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function rangeDates(string $from, string $to): array
    {
        return array_map(
            fn ($day): string => $day->toDateString(),
            iterator_to_array(CarbonPeriod::create(CarbonImmutable::parse($from), CarbonImmutable::parse($to))),
        );
    }
}
