<?php

namespace Tests\Feature;

use App\Livewire\ForecastingPage;
use App\Models\DailySkuFeature;
use App\Models\ForecastRecord;
use App\Models\Module;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ForecastingPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOMORROW = '2026-10-23';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
        config([
            'services.ml_forecast.url' => 'http://ml-forecast.test',
            'services.ml_forecast.dashboard_url' => null,
        ]);
        Http::preventStrayRequests();
    }

    public function test_roles_without_forecasting_access_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole('maintenance-staff'))
            ->get(route('forecasting.index'))
            ->assertForbidden();
    }

    public function test_view_access_sees_forecasts_but_cannot_generate(): void
    {
        $this->fakeHealth(['1L' => '2026-10-01']);
        $this->setForecastingAccess('inventory-staff', 'view');
        $this->createSku('1L');
        $this->actingAs($this->userWithRole('inventory-staff'));

        $this->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('Bottled Water 1L')
            ->assertDontSee('Generate forecasts');

        Livewire::test(ForecastingPage::class)->call('generateForecasts')->assertForbidden();
        $this->assertDatabaseCount('forecast_records', 0);
    }

    public function test_full_access_generates_forecasts_and_sees_each_skus_result(): void
    {
        $this->createSku('1L');
        $this->createSku('5L', missingCoveredOffsets: [3]);
        Http::fake([
            'ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => ['1L' => '2026-10-01', '5L' => '2026-10-01']]),
            'ml-forecast.test/predict' => Http::response(['predicted_units_sold' => 842.4, 'model_version' => '2026-10-01', 'rmse_score' => 31.25]),
        ]);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(ForecastingPage::class)
            ->assertSee('Generate forecasts')
            ->call('generateForecasts')
            ->assertDispatched('forecasts-generated', message: 'Generated 1 of 2 forecasts.')
            ->assertSee('Generation results')
            ->assertSee('842 units forecast for 23 Oct 2026.')
            ->assertSee('Missing 1 of 14 covered days before 23 Oct 2026: 20 Oct 2026.')
            ->assertSee('31.3')
            ->assertSee('14/14 days')
            ->assertSee('13/14 days');

        Http::assertSentCount(2);
        $this->assertDatabaseHas('forecast_records', [
            'forecast_date' => self::TOMORROW,
            'predicted_demand' => 842.4,
            'model_version' => '2026-10-01',
        ]);
    }

    public function test_only_administrators_see_the_ml_service_address(): void
    {
        $this->createSku('1L');
        Http::fake(['ml-forecast.test/health' => Http::failedConnection()]);
        $this->setForecastingAccess('inventory-staff', 'view');

        $this->actingAs($this->userWithRole('inventory-staff'))
            ->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('ML service unreachable')
            ->assertDontSee('ml-forecast.test');

        $this->actingAs($this->userWithRole('administrator'))
            ->get(route('forecasting.index'))
            ->assertSee('http://ml-forecast.test');
    }

    public function test_cards_explain_exactly_why_a_forecast_is_unavailable(): void
    {
        $this->createSku('1L', missingCoveredOffsets: [1, 2]);
        $this->createSku('5L', targetContext: false);
        $this->createSku('10L');
        Http::fake(['ml-forecast.test/health' => Http::failedConnection()]);
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('ML service unreachable')
            ->assertSee('Missing 2 of 14 covered days before 23 Oct 2026: 21 Oct 2026, 22 Oct 2026.')
            ->assertSee('No promotion, holiday, school-term and temperature context for 23 Oct 2026.')
            ->assertSee('The ML service is unreachable at http://ml-forecast.test.');
    }

    public function test_cards_separate_missing_models_from_forecasts_not_yet_generated(): void
    {
        $this->createSku('1L');
        $this->createSku('5L');
        $this->fakeHealth(['1L' => '2026-10-01']);
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('ML service reachable')
            ->assertSee('2026-10-01')
            ->assertSee('No forecast has been generated for 23 Oct 2026 yet.')
            ->assertSee('The ML service has no trained model for 5L.');
    }

    public function test_stored_forecasts_show_tomorrow_history_chart_and_model_metadata(): void
    {
        $product = $this->createSku('1L');
        $this->fakeHealth(['1L' => '2026-10-01']);
        ForecastRecord::query()->create([
            'product_id' => $product->id,
            'forecast_date' => self::TOMORROW,
            'predicted_demand' => 910,
            'horizon_days' => 1,
            'model_version' => '2026-10-01',
            'rmse_score' => 18.4,
            'generated_at' => now(),
        ]);
        ForecastRecord::query()->create([
            'product_id' => $product->id,
            'forecast_date' => '2026-10-20',
            'predicted_demand' => 640,
            'horizon_days' => 1,
            'model_version' => '2026-10-01',
            'rmse_score' => 18.4,
            'generated_at' => now(),
        ]);
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('910')
            ->assertSee('18.4')
            ->assertSee('Show data table')
            ->assertSee('640')
            ->assertDontSee('Forecast unavailable.');
    }

    public function test_evaluation_dashboard_link_appears_only_when_configured(): void
    {
        $this->fakeHealth([]);
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('forecasting.index'))->assertDontSee('Open evaluation dashboard');

        config(['services.ml_forecast.dashboard_url' => 'http://127.0.0.1:8501']);

        $this->get(route('forecasting.index'))
            ->assertSee('Open evaluation dashboard')
            ->assertSee('href="http://127.0.0.1:8501"', false);
    }

    public function test_page_without_products_shows_an_empty_state(): void
    {
        $this->fakeHealth([]);
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('forecasting.index'))
            ->assertOk()
            ->assertSee('No products to forecast yet');
    }

    public function test_query_count_does_not_grow_with_the_number_of_skus(): void
    {
        $this->fakeHealth([]);
        $this->actingAs($this->userWithRole('administrator'));
        $this->createSku('1L');
        $this->createSku('5L');
        Livewire::test(ForecastingPage::class);

        $twoSkuQueries = $this->countQueries(fn () => Livewire::test(ForecastingPage::class));

        foreach (['10L', '20L', '500ml'] as $sku) {
            $this->createSku($sku);
        }

        $fiveSkuQueries = $this->countQueries(fn () => Livewire::test(ForecastingPage::class));

        $this->assertSame($twoSkuQueries, $fiveSkuQueries);
    }

    public function test_generate_command_reports_each_sku(): void
    {
        $this->createSku('1L');
        $this->createSku('5L', targetContext: false);
        Http::fake(['ml-forecast.test/predict' => Http::response(['predicted_units_sold' => 500, 'model_version' => 'v1', 'rmse_score' => 2])]);

        $this->artisan('forecasts:generate')
            ->expectsOutputToContain('500 units forecast for 23 Oct 2026.')
            ->expectsOutputToContain('No promotion, holiday, school-term and temperature context for 23 Oct 2026.')
            ->expectsOutput('Generated 1 of 2 forecasts.')
            ->assertSuccessful();
    }

    /**
     * @param  array<int, int>  $missingCoveredOffsets  days before tomorrow without confirmed coverage
     */
    private function createSku(string $sku, array $missingCoveredOffsets = [], bool $targetContext = true): Product
    {
        $product = Product::query()->create(['name' => 'Bottled Water '.$sku, 'unit_type' => $sku, 'reorder_threshold' => 100]);
        $tomorrow = CarbonImmutable::parse(self::TOMORROW);

        for ($offset = 14; $offset >= 0; $offset--) {
            DailySkuFeature::query()->create([
                'product_sku' => $sku,
                'date' => $tomorrow->subDays($offset)->toDateString(),
                'is_promotion' => false,
                'is_public_holiday' => false,
                'is_school_term' => true,
                'sales_data_covered' => ! in_array($offset, $missingCoveredOffsets, true),
                'temperature_c' => $offset === 0 && ! $targetContext ? null : 23.5,
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, string>  $models
     */
    private function fakeHealth(array $models): void
    {
        Http::fake(['ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => $models])]);
    }

    private function setForecastingAccess(string $roleSlug, string $accessLevel): void
    {
        DB::table('role_module_permissions')
            ->where('role_id', Role::query()->where('slug', $roleSlug)->value('id'))
            ->where('module_id', Module::query()->where('slug', 'forecasting')->value('id'))
            ->update(['access_level' => $accessLevel]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id')]);
    }
}
