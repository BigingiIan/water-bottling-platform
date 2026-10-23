<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\KenyanCalendarFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class DemoDataSeeder extends Seeder
{
    private const CUSTOMER_NAME = 'DEMO — synthetic data';

    private const BATCH_NOTES = 'Demo data: synthetic demand forecasting seed.';

    /**
     * @var array<int, string>
     */
    private const INVENTORY_BATCH_NOTES = [
        'Demo inventory batch B-0934',
        'Demo inventory batch B-0932',
        'Demo inventory batch B-0930',
        'Demo inventory batch B-0935',
    ];

    /**
     * @var array<string, array{name: string, reorder_threshold: int}>
     */
    private const INVENTORY_PRODUCTS = [
        '500ml' => ['name' => 'Bottled Water 500ml', 'reorder_threshold' => 3000],
        '1L' => ['name' => 'Bottled Water 1L', 'reorder_threshold' => 2500],
        '5L' => ['name' => 'Bottled Water 5L', 'reorder_threshold' => 1500],
        '10L' => ['name' => 'Bottled Water 10L', 'reorder_threshold' => 500],
        '20L' => ['name' => 'Bottled Water 20L', 'reorder_threshold' => 250],
    ];

    /**
     * @var array<int, string>
     */
    private const SKU_LABELS = [
        '500ml',
        '1L',
        '5L',
        '10L',
        '20L',
    ];

    public function __construct(private readonly KenyanCalendarFeatures $calendarFeatures) {}

    /**
     * Load a weekday-aligned synthetic sales window for the production forecast demo.
     */
    public function run(): void
    {
        $this->ensureSafeEnvironment();

        $this->command?->warn('Loading synthetic demo data into the local database.');

        $window = $this->loadWindow();
        $administrator = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))
            ->orderBy('id')
            ->first();

        if ($administrator === null) {
            throw new RuntimeException('Demo data requires an existing administrator user. Seed TestUserSeeder first.');
        }

        DB::transaction(function () use ($window, $administrator): void {
            $customer = Customer::query()->firstOrCreate(
                ['name' => self::CUSTOMER_NAME],
                ['customer_type' => 'synthetic'],
            );
            $products = [];

            foreach (self::INVENTORY_PRODUCTS as $sku => $productData) {
                $products[$sku] = Product::query()->updateOrCreate(
                    ['unit_type' => $sku],
                    $productData,
                );
            }

            $timestamp = now();
            $batchDefinitions = [
                self::BATCH_NOTES => [
                    'batch_date' => today()->subDays(1)->toDateString(),
                    'raw_water_volume' => 7200,
                    'bottled_volume' => 6980,
                    'status' => 'completed',
                ],
                'Demo inventory batch B-0934' => [
                    'batch_date' => today()->toDateString(),
                    'raw_water_volume' => 9800,
                    'bottled_volume' => 9520,
                    'status' => 'completed',
                ],
                'Demo inventory batch B-0932' => [
                    'batch_date' => today()->toDateString(),
                    'raw_water_volume' => 2900,
                    'bottled_volume' => 2810,
                    'status' => 'completed',
                ],
                'Demo inventory batch B-0930' => [
                    'batch_date' => today()->subDays(3)->toDateString(),
                    'raw_water_volume' => 5200,
                    'bottled_volume' => 5060,
                    'status' => 'completed',
                ],
                'Demo inventory batch B-0935' => [
                    'batch_date' => today()->toDateString(),
                    'raw_water_volume' => 3100,
                    'bottled_volume' => 0,
                    'status' => 'in_progress',
                ],
            ];
            $batches = [];

            foreach ($batchDefinitions as $notes => $batchData) {
                $batches[$notes] = ProductionBatch::query()->updateOrCreate(
                    ['notes' => $notes],
                    [
                        ...$batchData,
                        'user_id' => $administrator->id,
                    ],
                );
            }

            $inventoryLines = [
                ['sku' => '500ml', 'batch' => self::BATCH_NOTES, 'location' => 'Bay A1', 'level' => 6400, 'minimum' => 1500, 'updated_at' => now()->setTime(7, 42)],
                ['sku' => '500ml', 'batch' => 'Demo inventory batch B-0934', 'location' => 'Bay A2', 'level' => 3200, 'minimum' => 1500, 'updated_at' => now()->subDay()->setTime(16, 10)],
                ['sku' => '1L', 'batch' => 'Demo inventory batch B-0932', 'location' => 'Bay B1', 'level' => 2150, 'minimum' => 2500, 'updated_at' => now()->setTime(9, 15)],
                ['sku' => '5L', 'batch' => 'Demo inventory batch B-0930', 'location' => 'Bay C1', 'level' => 3450, 'minimum' => 1500, 'updated_at' => now()->subDay()->setTime(14, 30)],
                ['sku' => '10L', 'batch' => 'Demo inventory batch B-0935', 'location' => 'Bay C2', 'level' => 610, 'minimum' => 500, 'updated_at' => now()->setTime(8, 0)],
                ['sku' => '20L', 'batch' => self::BATCH_NOTES, 'location' => 'Bay D1', 'level' => 90, 'minimum' => 250, 'updated_at' => now()->setTime(8, 20)],
            ];
            $stockIds = [];

            foreach ($inventoryLines as $line) {
                $product = $products[$line['sku']];
                DB::table('warehouse_stock')->updateOrInsert(
                    ['batch_id' => $batches[$line['batch']]->id, 'product_id' => $product->id],
                    [
                        'current_level' => $line['level'],
                        'warehouse_location' => $line['location'],
                        'minimum_threshold' => $line['minimum'],
                        'last_updated' => $line['updated_at'],
                    ],
                );

                $stockId = DB::table('warehouse_stock')
                    ->where('batch_id', $batches[$line['batch']]->id)
                    ->where('product_id', $product->id)
                    ->value('id');
                $stockIds[$line['sku']] ??= $stockId;
            }

            $this->seedInventoryMovements($stockIds, $products, $administrator->id, $timestamp);
            $this->seedLeakageReports($batches, $timestamp);

            $salesRows = [];
            $featureRows = [];
            $timestamp = now();

            foreach ($window['history'] as $historyDay) {
                $date = $historyDay['date'];

                foreach ($historyDay['rows'] as $sku => $row) {
                    $salesRows[] = [
                        'customer_id' => $customer->id,
                        'stock_id' => $stockIds[$sku],
                        'quantity' => $row['units_sold'],
                        'unit_price' => $row['unit_price_kes'],
                        'total_value' => $row['units_sold'] * $row['unit_price_kes'],
                        'transaction_date' => $date.' 12:00:00',
                    ];
                    $featureRows[] = $this->featureRow($sku, $date, $row, true, $timestamp);
                }
            }

            foreach ($window['target_rows'] as $sku => $row) {
                $featureRows[] = $this->featureRow($sku, today()->toDateString(), $row, false, $timestamp);
            }

            foreach ($salesRows as $salesRow) {
                DB::table('sales_transactions')->updateOrInsert(
                    [
                        'customer_id' => $salesRow['customer_id'],
                        'stock_id' => $salesRow['stock_id'],
                        'transaction_date' => $salesRow['transaction_date'],
                    ],
                    [
                        'quantity' => $salesRow['quantity'],
                        'unit_price' => $salesRow['unit_price'],
                        'total_value' => $salesRow['total_value'],
                    ],
                );
            }

            DB::table('daily_sku_features')->upsert(
                $featureRows,
                ['product_sku', 'date'],
                [
                    'is_promotion',
                    'is_public_holiday',
                    'is_school_term',
                    'temperature_c',
                    'sales_data_covered',
                    'updated_at',
                ],
            );

            $lineOne = ProductionLine::query()->firstOrCreate(
                ['name' => 'Line 01'],
                ['status' => 'active'],
            );
            $lineTwo = ProductionLine::query()->firstOrCreate(
                ['name' => 'Line 02'],
                ['status' => 'active'],
            );

            $scheduleRows = [
                ['sku' => '500ml', 'line' => $lineOne, 'time' => '08:00:00', 'status' => 'complete', 'units' => 3000],
                ['sku' => '1L', 'line' => $lineTwo, 'time' => '10:30:00', 'status' => 'active', 'units' => 2500],
                ['sku' => '5L', 'line' => $lineOne, 'time' => '13:00:00', 'status' => 'queued', 'units' => 1500],
                ['sku' => '20L', 'line' => $lineTwo, 'time' => '15:30:00', 'status' => 'queued', 'units' => 800],
            ];

            foreach ($scheduleRows as $schedule) {
                DB::table('production_schedules')->updateOrInsert(
                    [
                        'product_sku' => $schedule['sku'],
                        'scheduled_date' => today()->toDateString(),
                        'scheduled_time' => $schedule['time'],
                    ],
                    [
                        'production_line_id' => $schedule['line']->id,
                        'target_units' => $schedule['units'],
                        'status' => $schedule['status'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                );
            }
        });

        $this->command?->info('Loaded 30 days of synthetic sales, 31 days of SKU features, and four production schedules.');
    }

    /**
     * Remove the synthetic demo customer's sales and its dedicated production batch.
     */
    public function purge(): void
    {
        $this->ensureSafeEnvironment();

        DB::transaction(function (): void {
            $customer = Customer::query()->where('name', self::CUSTOMER_NAME)->first();

            if ($customer !== null) {
                $coveredDays = DB::table('sales_transactions')
                    ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
                    ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
                    ->where('sales_transactions.customer_id', $customer->id)
                    ->selectRaw('products.unit_type as product_sku, DATE(sales_transactions.transaction_date) as sales_date')
                    ->distinct()
                    ->get();

                foreach ($coveredDays as $coveredDay) {
                    DB::table('daily_sku_features')
                        ->where('product_sku', $coveredDay->product_sku)
                        ->whereDate('date', $coveredDay->sales_date)
                        ->update(['sales_data_covered' => false]);
                }

                DB::table('sales_transactions')->where('customer_id', $customer->id)->delete();
                $customer->delete();
            }

            $demoBatchIds = ProductionBatch::query()
                ->whereIn('notes', [self::BATCH_NOTES, ...self::INVENTORY_BATCH_NOTES])
                ->select('id');
            DB::table('stock_movements')
                ->whereIn('warehouse_stock_id', DB::table('warehouse_stock')->select('id')->whereIn('batch_id', $demoBatchIds))
                ->delete();
            ProductionBatch::query()
                ->whereIn('notes', [self::BATCH_NOTES, ...self::INVENTORY_BATCH_NOTES])
                ->delete();
        });

        $this->command?->info('Removed the synthetic demo customer, sales, inventory movements, and demo batches.');
    }

    /**
     * @param  array<string, int>  $stockIds
     * @param  array<string, Product>  $products
     */
    private function seedInventoryMovements(array $stockIds, array $products, int $administratorId, Carbon $timestamp): void
    {
        $movements = [
            [
                'reference' => 'TXN-4418',
                'sku' => '1L',
                'type' => 'sale',
                'quantity_change' => -600,
                'level_after' => 2150,
                'reason' => 'Sold to Naivas Kilimani',
                'user_id' => null,
                'created_at' => $timestamp->copy()->setTime(9, 15),
            ],
            [
                'reference' => 'DEMO:20L-DAMAGE',
                'sku' => '20L',
                'type' => 'adjustment',
                'quantity_change' => -12,
                'level_after' => 90,
                'reason' => 'Damaged in handling',
                'user_id' => $administratorId,
                'created_at' => $timestamp->copy()->setTime(8, 20),
            ],
            [
                'reference' => 'PR-1031',
                'sku' => '500ml',
                'type' => 'production',
                'quantity_change' => 1800,
                'level_after' => 6400,
                'reason' => null,
                'user_id' => null,
                'created_at' => $timestamp->copy()->setTime(7, 42),
            ],
            [
                'reference' => 'PR-1029',
                'sku' => '500ml',
                'type' => 'production',
                'quantity_change' => 3200,
                'level_after' => 3200,
                'reason' => null,
                'user_id' => null,
                'created_at' => $timestamp->copy()->subDay()->setTime(16, 10),
            ],
            [
                'reference' => 'B-0927',
                'sku' => '20L',
                'type' => 'leakage_writeoff',
                'quantity_change' => -70,
                'level_after' => 102,
                'reason' => 'Leakage write-off',
                'user_id' => $administratorId,
                'created_at' => $timestamp->copy()->subDay()->setTime(11, 3),
            ],
        ];

        foreach ($movements as $movement) {
            DB::table('stock_movements')->updateOrInsert(
                [
                    'warehouse_stock_id' => $stockIds[$movement['sku']],
                    'reference' => $movement['reference'],
                ],
                [
                    'product_id' => $products[$movement['sku']]->id,
                    'type' => $movement['type'],
                    'quantity_change' => $movement['quantity_change'],
                    'level_after' => $movement['level_after'],
                    'reason' => $movement['reason'],
                    'user_id' => $movement['user_id'],
                    'created_at' => $movement['created_at'],
                ],
            );
        }
    }

    /**
     * @param  array<string, ProductionBatch>  $batches
     */
    private function seedLeakageReports(array $batches, Carbon $timestamp): void
    {
        $reports = [
            ['batch' => self::BATCH_NOTES, 'date' => $timestamp->copy()->subDay()->toDateString(), 'expected' => 7200, 'actual' => 7028],
            ['batch' => 'Demo inventory batch B-0932', 'date' => $timestamp->copy()->subDays(4)->toDateString(), 'expected' => 1000, 'actual' => 930],
            ['batch' => 'Demo inventory batch B-0930', 'date' => $timestamp->copy()->subDays(2)->toDateString(), 'expected' => 5200, 'actual' => 5152],
            ['batch' => 'Demo inventory batch B-0934', 'date' => $timestamp->copy()->subDays(3)->toDateString(), 'expected' => 2400, 'actual' => 2388],
        ];

        foreach ($reports as $report) {
            DB::table('leakage_reports')->updateOrInsert(
                [
                    'batch_id' => $batches[$report['batch']]->id,
                    'report_date' => $report['date'],
                    'expected_volume' => $report['expected'],
                ],
                [
                    'actual_volume' => $report['actual'],
                    'discrepancy' => $report['actual'] - $report['expected'],
                ],
            );
        }
    }

    /**
     * @param  array{is_promotion: bool, temperature_c: float|int}  $row
     * @return array<string, bool|float|int|string>
     */
    private function featureRow(string $sku, string $date, array $row, bool $salesCovered, string $timestamp): array
    {
        $calendarDate = Carbon::parse($date);

        return [
            'product_sku' => $sku,
            'date' => $date,
            'is_promotion' => $row['is_promotion'],
            'is_public_holiday' => $this->calendarFeatures->isPublicHoliday($calendarDate),
            'is_school_term' => $this->calendarFeatures->isSchoolTerm($calendarDate),
            'temperature_c' => $row['temperature_c'],
            'sales_data_covered' => $salesCovered,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @return array{
     *     target_rows: array<string, array{units_sold: float, unit_price_kes: float, is_promotion: bool, temperature_c: float}>,
     *     history: array<int, array{date: string, rows: array<string, array{units_sold: float, unit_price_kes: float, is_promotion: bool, temperature_c: float}>}>
     * }
     */
    private function loadWindow(): array
    {
        $path = base_path('data/kenya_water_demand_daily.csv');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Synthetic training data is missing or unreadable: {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $headers = $file->fgetcsv();

        if (! is_array($headers)) {
            throw new RuntimeException('Synthetic training CSV has no header row.');
        }

        $columns = array_flip($headers);
        foreach (['date', 'product_sku', 'units_sold', 'unit_price_kes', 'is_promotion', 'temperature_c'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columns)) {
                throw new RuntimeException("Synthetic training CSV is missing the {$requiredColumn} column.");
            }
        }

        /** @var array<string, array<string, array{units_sold: float, unit_price_kes: float, is_promotion: bool, temperature_c: float}>> $rowsByDate */
        $rowsByDate = [];

        foreach ($file as $values) {
            if (! is_array($values) || count($values) !== count($headers)) {
                continue;
            }

            $sourceDate = trim((string) $values[$columns['date']]);
            $sku = trim((string) $values[$columns['product_sku']]);

            if ($sourceDate === 'date' && $sku === 'product_sku') {
                continue;
            }

            if ($sourceDate === '' || $sku === '') {
                continue;
            }

            $unitsSold = $values[$columns['units_sold']];
            $unitPrice = $values[$columns['unit_price_kes']];
            $temperature = $values[$columns['temperature_c']];

            if (! is_numeric($unitsSold) || ! is_numeric($unitPrice) || ! is_numeric($temperature)) {
                throw new RuntimeException("Synthetic training CSV contains invalid numeric data for {$sku} on {$sourceDate}.");
            }

            $rowsByDate[$sourceDate][$sku] = [
                'units_sold' => (float) $unitsSold,
                'unit_price_kes' => (float) $unitPrice,
                'is_promotion' => filter_var($values[$columns['is_promotion']], FILTER_VALIDATE_BOOL),
                'temperature_c' => (float) $temperature,
            ];
        }

        $dates = array_keys($rowsByDate);
        sort($dates);
        $targetSourceDate = null;

        foreach (array_reverse($dates) as $sourceDate) {
            $sourceDateValue = CarbonImmutable::createFromFormat('!Y-m-d', $sourceDate);

            if ($sourceDateValue instanceof CarbonImmutable && $sourceDateValue->dayOfWeek === today()->dayOfWeek) {
                $targetSourceDate = $sourceDate;
                break;
            }
        }

        if ($targetSourceDate === null) {
            throw new RuntimeException('Synthetic training CSV has no date matching today’s weekday.');
        }

        $targetIndex = array_search($targetSourceDate, $dates, true);

        if (! is_int($targetIndex) || $targetIndex < 30) {
            throw new RuntimeException('Synthetic training CSV does not contain 30 history days before the target date.');
        }

        $selectedDates = array_slice($dates, $targetIndex - 30, 31);
        foreach ($selectedDates as $index => $sourceDate) {
            if ($index > 0) {
                $previousDate = CarbonImmutable::parse($selectedDates[$index - 1]);

                if (! $previousDate->addDay()->isSameDay(CarbonImmutable::parse($sourceDate))) {
                    throw new RuntimeException('Synthetic training CSV must contain 31 consecutive dates for the demo window.');
                }
            }
        }

        $skus = self::SKU_LABELS;
        sort($skus);
        $history = [];

        foreach (array_slice($selectedDates, 0, 30) as $index => $sourceDate) {
            $mappedDate = today()->subDays(30 - $index)->toDateString();
            $history[] = [
                'date' => $mappedDate,
                'rows' => $this->rowsForSkus($rowsByDate[$sourceDate] ?? [], $skus, $sourceDate),
            ];
        }

        $targetRows = $this->rowsForSkus($rowsByDate[$targetSourceDate], $skus, $targetSourceDate);

        return [
            'target_rows' => $targetRows,
            'history' => $history,
        ];
    }

    /**
     * @param  array<string, array{units_sold: float, unit_price_kes: float, is_promotion: bool, temperature_c: float}>  $rows
     * @param  array<int, string>  $skus
     * @return array<string, array{units_sold: float, unit_price_kes: float, is_promotion: bool, temperature_c: float}>
     */
    private function rowsForSkus(array $rows, array $skus, string $date): array
    {
        foreach ($skus as $sku) {
            if (! array_key_exists($sku, $rows)) {
                throw new RuntimeException("Synthetic training CSV is missing {$sku} data for {$date}.");
            }
        }

        return array_intersect_key($rows, array_flip($skus));
    }

    private function ensureSafeEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoDataSeeder is restricted to local and testing environments.');
        }
    }
}
