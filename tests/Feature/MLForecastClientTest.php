<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailySkuFeature;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\MLForecastClient;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MLForecastClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['services.ml_forecast.url' => 'http://ml-forecast.test']);
        Http::preventStrayRequests();
    }

    public function test_it_sends_daily_sales_and_features_to_the_prediction_service(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fake([
            'ml-forecast.test/predict' => Http::response([
                'predicted_units_sold' => 875.5,
                'model_version' => '2026-10-01',
                'rmse_score' => 12.3456,
            ]),
        ]);

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertSame(875.5, $prediction);
        Http::assertSent(function (Request $request) use ($target): bool {
            $payload = $request->data();

            return $request->url() === 'http://ml-forecast.test/predict'
                && $payload['sku'] === '1L'
                && $payload['date'] === $target->toDateString()
                && count($payload['history']) === 14
                && $payload['history'][0]['date'] === $target->subDays(14)->toDateString()
                && $payload['history'][13]['date'] === $target->subDay()->toDateString()
                && $payload['history'][0]['units_sold'] === 0.0
                && $payload['history'][13]['units_sold'] === 27.5
                && $payload['history'][13]['is_promotion'] === true
                && $payload['is_public_holiday'] === true
                && $payload['temperature_c'] === 24.5;
        });
        $this->assertDatabaseHas('forecast_records', [
            'product_id' => Product::query()->where('unit_type', '1L')->value('id'),
            'forecast_date' => $target->toDateString(),
            'predicted_demand' => 875.5,
            'horizon_days' => 1,
            'model_version' => '2026-10-01',
            'rmse_score' => 12.3456,
        ]);
    }

    public function test_repeated_prediction_upserts_by_product_date_and_model_version(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fake([
            'ml-forecast.test/predict' => Http::sequence()
                ->push([
                    'predicted_units_sold' => 875.5,
                    'model_version' => '2026-10-01',
                    'rmse_score' => 12.3456,
                ])
                ->push([
                    'predicted_units_sold' => 900,
                    'model_version' => '2026-10-01',
                    'rmse_score' => 12.3456,
                ]),
        ]);

        $client = app(MLForecastClient::class);
        $client->predict('1L', $target->toDateString());
        $prediction = $client->predict('1L', $target->toDateString());

        $this->assertSame(900.0, $prediction);
        $this->assertDatabaseCount('forecast_records', 1);
        $this->assertDatabaseHas('forecast_records', [
            'forecast_date' => $target->toDateString(),
            'predicted_demand' => 900,
            'model_version' => '2026-10-01',
            'rmse_score' => 12.3456,
        ]);
    }

    public function test_it_returns_null_when_sales_coverage_is_not_confirmed(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', $target->subDays(8)->toDateString())
            ->update(['sales_data_covered' => false]);

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertNull($prediction);
        Http::assertNothingSent();
    }

    public function test_it_returns_null_when_any_required_daily_feature_is_missing(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', $target->subDays(3)->toDateString())
            ->update(['temperature_c' => null]);

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertNull($prediction);
        Http::assertNothingSent();
    }

    public function test_it_returns_null_when_the_history_has_a_missing_day(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', $target->subDays(8)->toDateString())
            ->delete();

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertNull($prediction);
        Http::assertNothingSent();
    }

    public function test_it_returns_null_when_the_prediction_service_is_down(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fake([
            'ml-forecast.test/predict' => Http::failedConnection(),
        ]);

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertNull($prediction);
    }

    public function test_it_returns_null_when_the_prediction_has_no_training_metadata(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fake([
            'ml-forecast.test/predict' => Http::response([
                'predicted_units_sold' => 875.5,
                'model_version' => '2026-10-01',
            ]),
        ]);

        $prediction = app(MLForecastClient::class)->predict('1L', $target->toDateString());

        $this->assertNull($prediction);
        $this->assertDatabaseCount('forecast_records', 0);
    }

    private function createSkuHistory(string $sku, CarbonImmutable $target): void
    {
        $product = Product::create([
            'name' => 'Bottled Water '.$sku,
            'unit_type' => $sku,
        ]);

        for ($offset = 14; $offset >= 0; $offset--) {
            DailySkuFeature::create([
                'product_sku' => $sku,
                'date' => $target->subDays($offset)->toDateString(),
                'is_promotion' => true,
                'is_public_holiday' => $offset === 0,
                'is_school_term' => true,
                'sales_data_covered' => true,
                'temperature_c' => 24.5,
            ]);
        }

        $operatorRole = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $operatorRole->id]);
        $customer = Customer::create(['name' => 'Test customer']);
        $batch = ProductionBatch::create([
            'user_id' => $user->id,
            'batch_date' => $target->subDay()->toDateString(),
            'raw_water_volume' => 1000,
            'bottled_volume' => 1000,
            'status' => 'completed',
        ]);
        $stockId = DB::table('warehouse_stock')->insertGetId([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 1000,
        ]);

        DB::table('sales_transactions')->insert([
            [
                'customer_id' => $customer->id,
                'stock_id' => $stockId,
                'quantity' => 12.5,
                'unit_price' => 1,
                'total_value' => 12.5,
                'transaction_date' => $target->subDay()->setTime(10, 0)->toDateTimeString(),
            ],
            [
                'customer_id' => $customer->id,
                'stock_id' => $stockId,
                'quantity' => 15,
                'unit_price' => 1,
                'total_value' => 15,
                'transaction_date' => $target->subDay()->setTime(15, 0)->toDateTimeString(),
            ],
        ]);
    }
}
