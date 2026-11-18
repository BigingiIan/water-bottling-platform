<?php

namespace App\Console\Commands;

use App\Services\Calendar\ContextCalendar;
use App\Services\Demo\DemoCatalog;
use App\Services\Demo\DemoDataBuilder;
use App\Services\Demo\DemoDataCleaner;
use App\Services\Demo\DemoDataset;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductionLineSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('demo:seed
    {--days=365 : Days of trading history, ending yesterday (today is added as a closed day)}
    {--customers=40 : How many customers to invent}
    {--fresh : Rebuild the database first (migrate:fresh and the base seeders)}')]
#[Description('Load a deterministic, consistent demo business for screenshots and walkthroughs (local and testing only)')]
class SeedDemoData extends Command
{
    /**
     * @var list<class-string>
     */
    private const BASE_SEEDERS = [
        RoleSeeder::class,
        ModuleSeeder::class,
        RoleModulePermissionSeeder::class,
        ProductionLineSeeder::class,
        PublicHolidaySeeder::class,
        ProductCatalogSeeder::class,
    ];

    public function handle(DemoDataBuilder $builder, DemoDataCleaner $cleaner, ContextCalendar $calendar): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('demo:seed only runs in the local and testing environments.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $customers = (int) $this->option('customers');

        if ($days < DemoDataBuilder::MIN_DAYS || $customers < 1) {
            $this->error('Use --days='.DemoDataBuilder::MIN_DAYS.' or more and at least one customer.');

            return self::INVALID;
        }

        $started = microtime(true);

        if ($this->option('fresh')) {
            $this->components->task('Rebuilding the database', fn () => $this->callSilently('migrate:fresh', ['--seed' => true, '--force' => true]) === self::SUCCESS);
        } elseif ($cleaner->hasDemoData()) {
            $this->components->task('Removing the previous demo data', fn () => $cleaner->clear());
        }

        if (DB::table('orders')->exists() || DB::table('warehouse_stock')->exists()) {
            $this->error('This database already has orders or stock that are not demo data, and the demo would mix with them. Run php artisan demo:seed --fresh to start from an empty database.');

            return self::FAILURE;
        }

        $this->components->task('Seeding roles, modules, lines, holidays and products', function (): void {
            foreach (self::BASE_SEEDERS as $seeder) {
                $this->callSilently('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        });

        $this->components->task("Building {$days} days of history", fn () => $builder->build($days, $customers));

        $today = CarbonImmutable::today();
        $first = $today->subDays($days);
        $this->components->task('Rebuilding forecast context for the next 30 days', fn () => $calendar->rebuild($today->addDay(), $today->addDays(30)));
        $this->components->task('Closing every sales day ('.$first->toDateString().' to '.$today->toDateString().')', function () use ($first, $today): void {
            for ($day = $first; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
                $this->callSilently('sales:close-day', ['date' => $day->toDateString()]);
            }
        });

        $forecastsBefore = (int) DB::table('forecast_records')->max('id');
        $this->call('forecasts:generate');
        $forecastsAfter = (int) DB::table('forecast_records')->max('id');

        if ($forecastsAfter > $forecastsBefore) {
            DemoDataset::recordIds('forecast_records', $forecastsBefore + 1, $forecastsAfter);
        }

        $this->components->task("Adding this evening's orders and tomorrow's runs", fn () => $builder->addEveningActivity());

        $this->newLine();
        $this->table(['Table', 'Rows'], collect($builder->insertedCounts())
            ->put('daily_sku_features (covered)', DB::table('daily_sku_features')->where('sales_data_covered', true)->count())
            ->put('forecast_records', $forecastsAfter - $forecastsBefore)
            ->map(fn (int $count, string $table): array => [$table, number_format($count)])
            ->values()
            ->all());

        $this->table(['Demo account', 'Email', 'Role'], array_map(fn (array $user): array => [
            $user['name'],
            $user['email'].'@'.DemoCatalog::EMAIL_DOMAIN,
            $user['role'],
        ], DemoCatalog::USERS));
        $this->info('Every demo account signs in with the password: '.DemoCatalog::PASSWORD);
        $this->line(sprintf('Done in %.1f s. Check it with php artisan demo:verify; remove it with php artisan demo:clear.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
