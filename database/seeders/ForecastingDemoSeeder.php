<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Services\Forecasting\DailyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

/**
 * Replays the last 60 days of the synthetic Kenyan demand CSV so forecasting works end to end locally.
 *
 * Every row it writes can be traced back and removed: sales belong to the "Demo Customer — …" customers
 * (customer_type "demo"), and the only batch it may create carries HISTORY_BATCH_NOTES.
 */
class ForecastingDemoSeeder extends Seeder
{
    public const REPLAY_DAYS = 60;

    public const DEMO_CUSTOMER_TYPE = 'demo';

    /**
     * @var array<int, string>
     */
    public const DEMO_CUSTOMERS = [
        'Demo Customer — Nairobi Retail',
        'Demo Customer — Mombasa Distributor',
        'Demo Customer — Kisumu Office',
    ];

    /**
     * Customers written by earlier versions of the demo seeders; their sales are replaced too.
     *
     * @var array<int, string>
     */
    private const LEGACY_DEMO_CUSTOMERS = ['DEMO — synthetic data'];

    public const HISTORY_BATCH_NOTES = 'Demo data: forecasting sales history (holds no stock).';

    /**
     * How a day's units are split across 2 or 3 demo customers.
     *
     * @var array<int, array<int, float>>
     */
    private const CUSTOMER_SPLITS = [
        2 => [0.6, 0.4],
        3 => [0.5, 0.3, 0.2],
    ];

    /**
     * UTC times that fall on the same calendar day in Africa/Nairobi (UTC+3).
     *
     * @var array<int, string>
     */
    private const SALE_TIMES = ['06:00:00', '10:00:00', '14:00:00'];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_COLUMNS = ['date', 'product_sku', 'units_sold', 'unit_price_kes', 'is_promotion', 'is_public_holiday', 'is_school_term'];

    public function __construct(private readonly DailyContext $context) {}

    public function run(): void
    {
        $this->ensureSafeEnvironment();

        $replay = $this->loadReplay();
        $skus = array_keys($replay['rows']);
        $this->context->assertClimatologyFor($skus);
        $tomorrow = CarbonImmutable::today()->addDay();

        DB::transaction(function () use ($replay, $skus, $tomorrow): void {
            $this->removeDemoSales();
            Customer::query()->whereIn('name', self::LEGACY_DEMO_CUSTOMERS)->delete();

            $customerIds = $this->ensureCustomers();
            $stockIds = $this->stockLinesFor($skus);
            $timestamp = now();
            $featureRows = [];
            $salesRows = [];

            foreach ($replay['rows'] as $sku => $days) {
                foreach (array_values($days) as $dayIndex => $day) {
                    // Context comes from the CSV; sales_data_covered is only switched on after the sales exist.
                    $featureRows[] = [
                        'product_sku' => $sku,
                        'date' => $day['date'],
                        'is_promotion' => $day['is_promotion'],
                        'is_public_holiday' => $day['is_public_holiday'],
                        'is_school_term' => $day['is_school_term'],
                        'temperature_c' => $this->context->temperature($sku, $day['date']),
                        'sales_data_covered' => false,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    foreach ($this->splitAcrossCustomers($day['units_sold'], $dayIndex) as $saleIndex => $quantity) {
                        $salesRows[] = [
                            'customer_id' => $customerIds[$saleIndex],
                            'stock_id' => $stockIds[$sku],
                            'quantity' => $quantity,
                            'unit_price' => $day['unit_price_kes'],
                            'total_value' => round($quantity * $day['unit_price_kes'], 2),
                            'transaction_date' => $day['date'].' '.self::SALE_TIMES[$saleIndex],
                        ];
                    }
                }

                $featureRows[] = $this->tomorrowContext($sku, $tomorrow, $timestamp);
            }

            DB::table('daily_sku_features')->upsert(
                $featureRows,
                ['product_sku', 'date'],
                ['is_promotion', 'is_public_holiday', 'is_school_term', 'temperature_c', 'sales_data_covered', 'updated_at'],
            );

            // Replayed sales are history: warehouse_stock.current_level is deliberately not reduced,
            // so the demo inventory levels shown on the Inventory page stay as seeded.
            foreach (array_chunk($salesRows, 500) as $chunk) {
                DB::table('sales_transactions')->insert($chunk);
            }

            $this->setCoverage(true);
        });

        $this->command?->info(sprintf(
            'Replayed %d days (%s to %s) for %s and seeded context for %s.',
            self::REPLAY_DAYS,
            $replay['firstDate'],
            $replay['lastDate'],
            implode(', ', $skus),
            $tomorrow->toDateString(),
        ));
    }

    /**
     * Removes the demo customers, their sales, and the history batch, and un-covers the affected days.
     */
    public function purge(): void
    {
        $this->ensureSafeEnvironment();

        DB::transaction(function (): void {
            $this->removeDemoSales();
            Customer::query()->whereIn('name', [...self::DEMO_CUSTOMERS, ...self::LEGACY_DEMO_CUSTOMERS])->delete();
            ProductionBatch::query()->where('notes', self::HISTORY_BATCH_NOTES)->delete();
        });

        $this->command?->info('Removed the forecasting demo customers, their sales, and the history batch.');
    }

    private function removeDemoSales(): void
    {
        $this->setCoverage(false);

        DB::table('sales_transactions')
            ->whereIn('customer_id', $this->demoCustomerIds(includeLegacy: true))
            ->delete();
    }

    /**
     * Marks (or un-marks) every SKU/day that has demo sales as covered.
     */
    private function setCoverage(bool $covered): void
    {
        DB::table('sales_transactions')
            ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->whereIn('sales_transactions.customer_id', $this->demoCustomerIds(includeLegacy: true))
            ->selectRaw('products.unit_type as product_sku, DATE(sales_transactions.transaction_date) as sales_date')
            ->distinct()
            ->get()
            ->groupBy('product_sku')
            ->each(function ($days, string $sku) use ($covered): void {
                foreach ($days->pluck('sales_date')->chunk(200) as $dates) {
                    DB::table('daily_sku_features')
                        ->where('product_sku', $sku)
                        ->whereIn('date', $dates->all())
                        ->update(['sales_data_covered' => $covered, 'updated_at' => now()]);
                }
            });
    }

    /**
     * @return array<int, int>
     */
    private function demoCustomerIds(bool $includeLegacy = false): array
    {
        $names = $includeLegacy ? [...self::DEMO_CUSTOMERS, ...self::LEGACY_DEMO_CUSTOMERS] : self::DEMO_CUSTOMERS;

        return Customer::query()->whereIn('name', $names)->pluck('id')->all();
    }

    /**
     * @return array<int, int> customer ids in DEMO_CUSTOMERS order
     */
    private function ensureCustomers(): array
    {
        return array_map(
            fn (string $name): int => Customer::query()->firstOrCreate(['name' => $name], ['customer_type' => self::DEMO_CUSTOMER_TYPE])->id,
            self::DEMO_CUSTOMERS,
        );
    }

    /**
     * Sales need a stock line; reuse each product's first line, or add an empty one to a dedicated demo batch.
     *
     * @param  array<int, string>  $skus
     * @return array<string, int>
     */
    private function stockLinesFor(array $skus): array
    {
        $stockIds = [];
        $historyBatch = null;

        foreach ($skus as $sku) {
            $product = Product::query()->firstOrCreate(['unit_type' => $sku], ['name' => "Bottled Water {$sku}"]);
            $stockId = DB::table('warehouse_stock')->where('product_id', $product->id)->orderBy('id')->value('id');

            if ($stockId === null) {
                $historyBatch ??= $this->historyBatch();
                $stockId = DB::table('warehouse_stock')->insertGetId([
                    'batch_id' => $historyBatch->id,
                    'product_id' => $product->id,
                    'current_level' => 0,
                    'warehouse_location' => 'Demo sales history',
                ]);
            }

            $stockIds[$sku] = (int) $stockId;
        }

        return $stockIds;
    }

    private function historyBatch(): ProductionBatch
    {
        $administrator = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))
            ->orderBy('id')
            ->first();

        if ($administrator === null) {
            throw new RuntimeException('Forecasting demo data needs an administrator to own its history batch. Seed TestUserSeeder first.');
        }

        return ProductionBatch::query()->firstOrCreate(
            ['notes' => self::HISTORY_BATCH_NOTES],
            [
                'user_id' => $administrator->id,
                'batch_date' => today()->toDateString(),
                'raw_water_volume' => 0,
                'bottled_volume' => 0,
                'status' => 'completed',
            ],
        );
    }

    /**
     * Splits a day's units across 2 or 3 customers (alternating by day) with whole-unit quantities.
     *
     * @return array<int, int>
     */
    private function splitAcrossCustomers(int $unitsSold, int $dayIndex): array
    {
        $shares = self::CUSTOMER_SPLITS[$dayIndex % 2 === 0 ? 3 : 2];
        $quantities = array_map(fn (float $share): int => (int) floor($unitsSold * $share), $shares);
        $quantities[0] += $unitsSold - array_sum($quantities);

        return array_filter($quantities, fn (int $quantity): bool => $quantity > 0);
    }

    /**
     * Tomorrow is past the end of the CSV. Holiday and school-term come from the Kenyan calendar service;
     * no promotion source exists, so an existing value is kept and otherwise "no promotion planned" (false)
     * is assumed. Edit the row if a promotion is scheduled.
     *
     * @return array<string, mixed>
     */
    private function tomorrowContext(string $sku, CarbonImmutable $tomorrow, Carbon $timestamp): array
    {
        $existingPromotion = DB::table('daily_sku_features')
            ->where('product_sku', $sku)
            ->whereDate('date', $tomorrow->toDateString())
            ->value('is_promotion');

        return [
            'product_sku' => $sku,
            'date' => $tomorrow->toDateString(),
            'is_promotion' => $existingPromotion === null ? false : (bool) $existingPromotion,
            'is_public_holiday' => $this->context->isPublicHoliday($tomorrow->toDateString()),
            'is_school_term' => $this->context->isSchoolTerm($tomorrow->toDateString()),
            'temperature_c' => $this->context->temperature($sku, $tomorrow->toDateString()),
            'sales_data_covered' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @return array{rows: array<string, array<int, array{date: string, units_sold: int, unit_price_kes: float, is_promotion: bool, is_public_holiday: bool, is_school_term: bool}>>, firstDate: string, lastDate: string}
     */
    private function loadReplay(): array
    {
        $path = base_path('data/kenya_water_demand_daily.csv');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Demand CSV is missing or unreadable: {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $headers = $file->fgetcsv();
        $columns = is_array($headers) ? array_flip($headers) : [];

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! array_key_exists($column, $columns)) {
                throw new RuntimeException("Demand CSV is missing the {$column} column.");
            }
        }

        $rowsByDate = [];

        foreach ($file as $values) {
            if (! is_array($values) || count($values) !== count($headers) || $values[$columns['date']] === 'date') {
                continue;
            }

            $unitsSold = $values[$columns['units_sold']];
            $unitPrice = $values[$columns['unit_price_kes']];

            if (! is_numeric($unitsSold) || ! is_numeric($unitPrice)) {
                throw new RuntimeException("Demand CSV has invalid numbers for {$values[$columns['product_sku']]} on {$values[$columns['date']]}.");
            }

            $rowsByDate[$values[$columns['date']]][$values[$columns['product_sku']]] = [
                'units_sold' => (int) round((float) $unitsSold),
                'unit_price_kes' => (float) $unitPrice,
                'is_promotion' => filter_var($values[$columns['is_promotion']], FILTER_VALIDATE_BOOL),
                'is_public_holiday' => filter_var($values[$columns['is_public_holiday']], FILTER_VALIDATE_BOOL),
                'is_school_term' => filter_var($values[$columns['is_school_term']], FILTER_VALIDATE_BOOL),
            ];
        }

        ksort($rowsByDate);
        $sourceDates = array_slice(array_keys($rowsByDate), -self::REPLAY_DAYS);

        if (count($sourceDates) < self::REPLAY_DAYS) {
            throw new RuntimeException('Demand CSV has fewer than '.self::REPLAY_DAYS.' days.');
        }

        // Shift so the CSV's final day lands on today: tomorrow's forecast needs 14 covered days ending today.
        $lastSourceDate = CarbonImmutable::parse(end($sourceDates));
        $shiftDays = (int) $lastSourceDate->diffInDays(CarbonImmutable::today());
        $skus = array_keys($rowsByDate[$sourceDates[0]]);
        sort($skus);
        $rows = array_fill_keys($skus, []);

        foreach ($sourceDates as $index => $sourceDate) {
            if ($index > 0 && ! CarbonImmutable::parse($sourceDates[$index - 1])->addDay()->isSameDay(CarbonImmutable::parse($sourceDate))) {
                throw new RuntimeException("Demand CSV is not consecutive around {$sourceDate}.");
            }

            $mappedDate = CarbonImmutable::parse($sourceDate)->addDays($shiftDays)->toDateString();

            foreach ($skus as $sku) {
                $row = $rowsByDate[$sourceDate][$sku] ?? throw new RuntimeException("Demand CSV is missing {$sku} on {$sourceDate}.");
                $rows[$sku][] = ['date' => $mappedDate, ...$row];
            }
        }

        return [
            'rows' => $rows,
            'firstDate' => $rows[$skus[0]][0]['date'],
            'lastDate' => $rows[$skus[0]][self::REPLAY_DAYS - 1]['date'],
        ];
    }

    private function ensureSafeEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('ForecastingDemoSeeder is restricted to local and testing environments.');
        }
    }
}
