<?php

namespace App\Services\Calendar;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PublicHoliday;
use App\Models\SchoolTerm;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the forecast's context features (is_promotion, is_public_holiday, is_school_term, temperature_c)
 * into daily_sku_features from the business calendar tables and the temperature source.
 *
 * Days whose sales_data_covered is true are history the model has already been fed; a rebuild leaves them
 * alone unless the caller has authorised changing history.
 */
class ContextCalendar
{
    private const UPSERT_CHUNK = 500;

    public function __construct(private TemperatureSource $temperature) {}

    /**
     * Rebuilds every product's features for each day from $from to $to inclusive.
     *
     * @param  list<int>|null  $productIds  Limit the rebuild to these products; null rebuilds them all.
     * @return array{written: int, skippedCovered: int, missingTemperature: array<string, string>}
     */
    public function rebuild(CarbonInterface $from, CarbonInterface $to, bool $includeCovered = false, ?array $productIds = null): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());
        $result = ['written' => 0, 'skippedCovered' => 0, 'missingTemperature' => []];

        if ($to->lessThan($from)) {
            return $result;
        }

        $products = Product::query()
            ->whereNotNull('unit_type')
            ->when($productIds !== null, fn ($query) => $query->whereIn('id', $productIds))
            ->orderBy('id')
            ->get(['id', 'unit_type']);

        if ($products->isEmpty()) {
            return $result;
        }

        $context = $this->contextBetween($from, $to);
        $existing = DB::table('daily_sku_features')
            ->whereIn('product_sku', $products->pluck('unit_type'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['product_sku', 'date', 'sales_data_covered', 'temperature_c', 'temperature_source'])
            ->keyBy(fn (object $row): string => $row->product_sku.'|'.substr((string) $row->date, 0, 10));
        $timestamp = now();
        $rows = [];

        foreach ($products as $product) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                $current = $existing->get($product->unit_type.'|'.$date);

                if ($current !== null && (bool) $current->sales_data_covered && ! $includeCovered) {
                    $result['skippedCovered']++;

                    continue;
                }

                $temperature = $this->temperature->temperatureFor($product->unit_type, $day);

                if ($temperature === null) {
                    $result['missingTemperature'][$product->unit_type] ??= (string) $this->temperature->unavailableReason($product->unit_type);
                }

                $rows[] = [
                    'product_sku' => $product->unit_type,
                    'date' => $date,
                    ...$this->flags($context, $product->id, $day),
                    // Keep a value already recorded rather than blanking it when the source is unavailable.
                    'temperature_c' => $temperature ?? $current?->temperature_c,
                    'temperature_source' => $temperature !== null ? $this->temperature->name() : $current?->temperature_source,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($rows) === self::UPSERT_CHUNK) {
                    $result['written'] += $this->upsert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $result['written'] += $this->upsert($rows);
        }

        return $result;
    }

    /**
     * Rebuilds a scattered set of dates, for example a recurring holiday's day in every year.
     *
     * @param  list<string>  $dates  Y-m-d
     * @param  list<int>|null  $productIds
     * @return array{written: int, skippedCovered: int, missingTemperature: array<string, string>}
     */
    public function rebuildDates(array $dates, bool $includeCovered = false, ?array $productIds = null): array
    {
        $total = ['written' => 0, 'skippedCovered' => 0, 'missingTemperature' => []];

        foreach ($this->contiguousRanges($dates) as [$from, $to]) {
            $result = $this->rebuild($from, $to, $includeCovered, $productIds);
            $total['written'] += $result['written'];
            $total['skippedCovered'] += $result['skippedCovered'];
            $total['missingTemperature'] += $result['missingTemperature'];
        }

        return $total;
    }

    /**
     * The three calendar flags for one product on one day, read straight from the calendar tables.
     *
     * @return array{is_promotion: bool, is_public_holiday: bool, is_school_term: bool}
     */
    public function flagsFor(int $productId, CarbonInterface $day): array
    {
        $day = CarbonImmutable::parse($day->toDateString());

        return $this->flags($this->contextBetween($day, $day), $productId, $day);
    }

    /**
     * How many already-covered feature rows fall on the given dates (optionally for some products only).
     *
     * @param  list<string>  $dates  Y-m-d
     * @param  list<string>|null  $skus
     */
    public function coveredDayCount(array $dates, ?array $skus = null): int
    {
        if ($dates === []) {
            return 0;
        }

        $count = 0;

        foreach ($this->contiguousRanges($dates) as [$from, $to]) {
            $count += DB::table('daily_sku_features')
                ->where('sales_data_covered', true)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->when($skus !== null, fn ($query) => $query->whereIn('product_sku', $skus))
                ->count();
        }

        return $count;
    }

    /**
     * Calendar years in the range that have no school terms recorded at all.
     *
     * @return list<int>
     */
    public function yearsWithoutSchoolTerms(CarbonInterface $from, CarbonInterface $to): array
    {
        $years = range((int) $from->format('Y'), (int) $to->format('Y'));
        $recorded = SchoolTerm::query()->whereIn('year', $years)->distinct()->pluck('year')->map(fn (mixed $year): int => (int) $year)->all();

        return array_values(array_diff($years, $recorded));
    }

    /**
     * Holidays, terms and promotions that can affect any day in the range, loaded with three queries.
     *
     * @return array{holidays: Collection<int, PublicHoliday>, terms: Collection<int, SchoolTerm>, promotions: Collection<int, Promotion>}
     */
    public function contextBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'holidays' => PublicHoliday::query()
                ->where(fn ($query) => $query
                    ->where('recurring', true)
                    // whereDate, not whereBetween: SQLite stores the date cast with a time part.
                    ->orWhere(fn ($query) => $query
                        ->whereDate('date', '>=', $from->toDateString())
                        ->whereDate('date', '<=', $to->toDateString())))
                ->get(),
            'terms' => SchoolTerm::query()
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->get(),
            'promotions' => Promotion::query()
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->get(),
        ];
    }

    /**
     * @param  array{holidays: Collection<int, PublicHoliday>, terms: Collection<int, SchoolTerm>, promotions: Collection<int, Promotion>}  $context
     * @return array{is_promotion: bool, is_public_holiday: bool, is_school_term: bool}
     */
    private function flags(array $context, int $productId, CarbonInterface $day): array
    {
        return [
            // Overlapping promotions still make one flag: the model only knows "promotion or not".
            'is_promotion' => $context['promotions']->contains(fn (Promotion $promotion): bool => $promotion->appliesTo($productId, $day)),
            'is_public_holiday' => $context['holidays']->contains(fn (PublicHoliday $holiday): bool => $holiday->fallsOn($day)),
            // A day outside every recorded term is a school holiday, or a year whose terms aren't entered yet.
            'is_school_term' => $context['terms']->contains(fn (SchoolTerm $term): bool => $term->covers($day)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): int
    {
        DB::table('daily_sku_features')->upsert(
            $rows,
            ['product_sku', 'date'],
            ['is_promotion', 'is_public_holiday', 'is_school_term', 'temperature_c', 'temperature_source', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function contiguousRanges(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $ranges = [];

        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date);
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][1]->addDay()->isSameDay($day)) {
                $ranges[$last][1] = $day;

                continue;
            }

            $ranges[] = [$day, $day];
        }

        return $ranges;
    }
}
