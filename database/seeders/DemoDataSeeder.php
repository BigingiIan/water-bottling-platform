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

            foreach (array_keys($window['target_rows']) as $sku) {
                $products[$sku] = Product::query()->firstOrCreate(
                    ['unit_type' => $sku],
                    ['name' => 'Bottled Water '.$sku],
                );
            }

            $batch = ProductionBatch::query()->updateOrCreate(
                ['notes' => self::BATCH_NOTES],
                [
                    'user_id' => $administrator->id,
                    'batch_date' => today()->toDateString(),
                    'raw_water_volume' => 10000,
                    'bottled_volume' => 8000,
                    'status' => 'completed',
                ],
            );

            $stockIds = [];

            foreach ($products as $sku => $product) {
                DB::table('warehouse_stock')->updateOrInsert(
                    ['batch_id' => $batch->id, 'product_id' => $product->id],
                    [
                        'current_level' => 0,
                        'warehouse_location' => 'Demo Warehouse',
                        'minimum_threshold' => 0,
                    ],
                );

                $stockIds[$sku] = DB::table('warehouse_stock')
                    ->where('batch_id', $batch->id)
                    ->where('product_id', $product->id)
                    ->value('id');
            }

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

            ProductionBatch::query()->where('notes', self::BATCH_NOTES)->delete();
        });

        $this->command?->info('Removed the synthetic demo customer, its sales, and the demo production batch.');
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
