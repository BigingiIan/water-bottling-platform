<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PublicHoliday;
use App\Models\SchoolTerm;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\ContextCalendar;
use App\Support\ModuleAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Business Calendar', 'breadcrumbSection' => 'Analytics', 'activeItem' => 'Business Calendar'])]
class CalendarPage extends Component
{
    private const TABS = ['month', 'holidays', 'terms', 'promotions'];

    private const ENTITIES = ['holiday', 'school_term', 'promotion'];

    #[Url(as: 'tab', except: 'month')]
    public string $activeTab = 'month';

    #[Url(as: 'month', except: '')]
    public string $month = '';

    #[Locked]
    public ?string $editingEntity = null;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?string $deletingEntity = null;

    #[Locked]
    public ?int $deletingId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public string $reason = '';

    public bool $needsReason = false;

    public function mount(): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'month';
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = '';
        }
    }

    public function showMonth(string $month): void
    {
        $this->month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : '';
        $this->activeTab = 'month';
    }

    public function openCreate(string $entity): void
    {
        $this->authorizeWrite();
        abort_unless(in_array($entity, self::ENTITIES, true), 404);

        $today = today()->toDateString();
        $this->resetForm();
        $this->editingEntity = $entity;
        $this->form = match ($entity) {
            'holiday' => ['name' => '', 'date' => $today, 'recurring' => false],
            'school_term' => ['year' => (int) today()->format('Y'), 'term' => 1, 'start_date' => '', 'end_date' => ''],
            'promotion' => ['name' => '', 'product_id' => '', 'start_date' => $today, 'end_date' => $today, 'type' => 'price_discount', 'discount_percent' => '', 'notes' => ''],
        };
    }

    public function openEdit(string $entity, int $id): void
    {
        $this->authorizeWrite();
        $record = $this->find($entity, $id);

        $this->resetForm();
        $this->editingEntity = $entity;
        $this->editingId = $record->id;
        $this->form = match (true) {
            $record instanceof PublicHoliday => [
                'name' => $record->name,
                // Recurring holidays are edited on this year's date; only the month and day are kept.
                'date' => $record->recurring ? today()->format('Y').$record->date->format('-m-d') : $record->date->toDateString(),
                'recurring' => $record->recurring,
            ],
            $record instanceof SchoolTerm => [
                'year' => $record->year,
                'term' => $record->term,
                'start_date' => $record->start_date->toDateString(),
                'end_date' => $record->end_date->toDateString(),
            ],
            $record instanceof Promotion => [
                'name' => $record->name,
                'product_id' => $record->product_id === null ? '' : (string) $record->product_id,
                'start_date' => $record->start_date->toDateString(),
                'end_date' => $record->end_date->toDateString(),
                'type' => $record->type,
                'discount_percent' => $record->discount_percent === null ? '' : (string) $record->discount_percent,
                'notes' => (string) $record->notes,
            ],
        };
    }

    public function save(CalendarService $calendar): void
    {
        $user = $this->authorizeWrite();
        abort_unless(in_array($this->editingEntity, self::ENTITIES, true), 404);
        $record = $this->editingId === null ? null : $this->find($this->editingEntity, $this->editingId);
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        $this->guardValidation(fn () => match ($this->editingEntity) {
            'holiday' => $calendar->saveHoliday($user, $record, $input, $this->reason),
            'school_term' => $calendar->saveSchoolTerm($user, $record, $input, $this->reason),
            'promotion' => $calendar->savePromotion($user, $record, $input, $this->reason),
        });

        $this->resetForm();
        $this->dispatch('calendar-saved');
    }

    public function confirmDelete(string $entity, int $id): void
    {
        $this->authorizeWrite();
        $record = $this->find($entity, $id);

        $this->resetForm();
        $this->deletingEntity = $entity;
        $this->deletingId = $record->id;
    }

    public function delete(CalendarService $calendar): void
    {
        $user = $this->authorizeWrite();
        abort_unless(in_array($this->deletingEntity, self::ENTITIES, true), 404);
        $record = $this->find($this->deletingEntity, (int) $this->deletingId);

        $this->guardValidation(fn () => $calendar->delete($user, $record, $this->reason));

        $this->resetForm();
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    public function render(ContextCalendar $context): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'calendar');

        $monthStart = $this->month === '' ? today()->startOfMonth()->toImmutable() : CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth();
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthStart->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $products = Product::query()->orderBy('unit_type')->get(['id', 'unit_type', 'name']);

        return view('livewire.calendar-page', [
            'canManage' => app(ModuleAccess::class)->can($user, 'calendar', write: true),
            'monthStart' => $monthStart,
            'weeks' => $this->activeTab === 'month' ? $this->weeks($context, $gridStart, $gridEnd, $products->pluck('unit_type', 'id')->all()) : [],
            'holidays' => $this->activeTab === 'holidays'
                ? PublicHoliday::query()->orderByDesc('recurring')->orderBy('date')->get()
                : collect(),
            'terms' => $this->activeTab === 'terms' ? SchoolTerm::query()->orderByDesc('year')->orderBy('term')->get() : collect(),
            'promotions' => $this->activeTab === 'promotions' ? Promotion::query()->with('product:id,unit_type')->orderByDesc('start_date')->get() : collect(),
            'products' => $products,
            'missingTermYears' => $context->yearsWithoutSchoolTerms($monthStart, $monthStart->endOfMonth()),
        ]);
    }

    /**
     * The month grid, one row per week (Monday first), with each day's holidays, term and promotions.
     *
     * @param  array<int, string>  $skusByProductId
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, holidays: list<string>, term: ?string, promotions: list<string>}>>
     */
    private function weeks(ContextCalendar $context, CarbonImmutable $from, CarbonImmutable $to, array $skusByProductId): array
    {
        $entries = $context->contextBetween($from, $to);
        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $day = CarbonImmutable::parse($day);
            $term = $entries['terms']->first(fn (SchoolTerm $term): bool => $term->covers($day));

            $days[] = [
                'date' => $day,
                'inMonth' => $day->month === (int) substr($this->month === '' ? today()->format('Y-m') : $this->month, 5, 2),
                'isToday' => $day->isToday(),
                'holidays' => $entries['holidays']->filter(fn (PublicHoliday $holiday): bool => $holiday->fallsOn($day))->pluck('name')->values()->all(),
                'term' => $term ? __('calendar.term_label', ['term' => $term->term]) : null,
                'promotions' => $entries['promotions']
                    ->filter(fn (Promotion $promotion): bool => $day->betweenIncluded($promotion->start_date, $promotion->end_date))
                    ->map(fn (Promotion $promotion): string => $promotion->name.($promotion->product_id === null ? '' : ' · '.($skusByProductId[$promotion->product_id] ?? '')))
                    ->values()
                    ->all(),
            ];
        }

        return array_chunk($days, 7);
    }

    /**
     * Runs a save or delete, moving field errors under "form." so they appear next to their inputs and
     * revealing the reason field when the change touches covered sales history.
     */
    private function guardValidation(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->needsReason = $this->needsReason || array_key_exists('reason', $exception->errors());

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [$field === 'reason' ? 'reason' : "form.{$field}" => $messages])
                ->all());
        }
    }

    private function find(string $entity, int $id): PublicHoliday|SchoolTerm|Promotion
    {
        return match ($entity) {
            'holiday' => PublicHoliday::query()->findOrFail($id),
            'school_term' => SchoolTerm::query()->findOrFail($id),
            'promotion' => Promotion::query()->findOrFail($id),
            default => abort(404),
        };
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingEntity', 'editingId', 'deletingEntity', 'deletingId', 'form', 'reason', 'needsReason']);
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'calendar', write: true);

        return $user;
    }
}
