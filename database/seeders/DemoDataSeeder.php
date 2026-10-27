<?php

namespace Database\Seeders;

use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
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
     * Load the inventory and production demo, then replay the forecasting sales history onto its stock lines.
     */
    public function run(): void
    {
        $this->ensureSafeEnvironment();

        $this->command?->warn('Loading synthetic demo data into the local database.');

        $administrator = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))
            ->orderBy('id')
            ->first();

        if ($administrator === null) {
            throw new RuntimeException('Demo data requires an existing administrator user. Seed TestUserSeeder first.');
        }

        DB::transaction(function () use ($administrator): void {
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

        // Sales history and SKU features are owned by ForecastingDemoSeeder (60-day CSV replay).
        app(ProductCatalogSeeder::class)->run();
        $forecastingSeeder = app(ForecastingDemoSeeder::class);

        if ($this->command !== null) {
            $forecastingSeeder->setCommand($this->command);
        }

        $forecastingSeeder->run();

        $this->command?->info('Loaded the inventory demo, four production schedules, and the forecasting sales history.');
    }

    /**
     * Remove the forecasting demo sales, then the inventory demo batches and their movements.
     */
    public function purge(): void
    {
        $this->ensureSafeEnvironment();

        app(ForecastingDemoSeeder::class)->purge();

        DB::transaction(function (): void {
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

        $this->command?->info('Removed the demo sales history, inventory movements, and demo batches.');
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
            // Through the model so each report is graded with the leakage thresholds; matched by date so
            // re-running updates rather than duplicates.
            $leakageReport = LeakageReport::query()
                ->where('batch_id', $batches[$report['batch']]->id)
                ->whereDate('report_date', $report['date'])
                ->where('expected_volume', $report['expected'])
                ->first() ?? new LeakageReport([
                    'batch_id' => $batches[$report['batch']]->id,
                    'report_date' => $report['date'],
                    'expected_volume' => $report['expected'],
                ]);
            $leakageReport->fill([
                'actual_volume' => $report['actual'],
                'discrepancy' => $report['actual'] - $report['expected'],
            ])->save();
        }
    }

    private function ensureSafeEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoDataSeeder is restricted to local and testing environments.');
        }
    }
}
