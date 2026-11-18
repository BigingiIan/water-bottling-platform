<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailySkuFeature;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\Forecasting\ForecastStatus;
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

    public function test_forecast_explains_missing_covered_days_with_their_dates(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        DailySkuFeature::query()->whereIn('date', ['2026-09-25', '2026-09-26'])->update(['sales_data_covered' => false]);

        $outcome = app(MLForecastClient::class)->forecast('1L', $target->toDateString());

        $this->assertSame(ForecastStatus::MissingCoveredDays, $outcome->status);
        $this->assertSame(['2026-09-25', '2026-09-26'], $outcome->missingDates);
        $this->assertSame('Missing 2 of 14 covered days before 04 Oct 2026: 25 Sep 2026, 26 Sep 2026. Confirm sales coverage for these days.', $outcome->message());
        Http::assertNothingSent();

        DailySkuFeature::query()->update(['sales_data_covered' => false]);

        $this->assertSame(
            'No confirmed sales coverage for the 14 days before 04 Oct 2026. The model needs all of them.',
            app(MLForecastClient::class)->forecast('1L', $target->toDateString())->message(),
        );
    }

    public function test_forecast_explains_missing_target_day_context(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        DailySkuFeature::query()->where('date', $target->toDateString())->update(['temperature_c' => null]);

        $outcome = app(MLForecastClient::class)->forecast('1L', $target->toDateString());

        $this->assertSame(ForecastStatus::MissingContext, $outcome->status);
        $this->assertSame('No promotion, holiday, school-term and temperature context for 04 Oct 2026.', $outcome->message());
    }

    public function test_forecast_distinguishes_unreachable_service_from_missing_sku_model(): void
    {
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fakeSequence('ml-forecast.test/predict')
            ->pushFailedConnection()
            ->push(['detail' => 'No trained model is available for this SKU'], 503)
            ->push(['detail' => 'The model could not score this request'], 503);

        $client = app(MLForecastClient::class);

        $unreachable = $client->forecast('1L', '2026-10-04');
        $noModel = $client->forecast('1L', '2026-10-04');
        $serviceError = $client->forecast('1L', '2026-10-04');

        $this->assertSame(ForecastStatus::ServiceUnreachable, $unreachable->status);
        $this->assertSame('The ML service is unreachable at http://ml-forecast.test.', $unreachable->message());
        $this->assertSame(ForecastStatus::NoModelForSku, $noModel->status);
        $this->assertSame('The ML service has no trained model for 1L.', $noModel->message());
        $this->assertSame(ForecastStatus::ServiceError, $serviceError->status);
        $this->assertSame('The ML service could not score this request (HTTP 503).', $serviceError->message());
    }

    public function test_health_reports_reachability_and_served_versions_once_per_request(): void
    {
        Http::fake(['ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => ['1L' => '2026-10-01']])]);
        $client = app(MLForecastClient::class);

        $this->assertSame(['reachable' => true, 'models' => ['1L' => '2026-10-01'], 'error' => null], $client->health());
        $client->health();
        Http::assertSentCount(1);
    }

    public function test_health_explains_an_outdated_service_without_the_health_endpoint(): void
    {
        Http::fake(['ml-forecast.test/health' => Http::response(['detail' => 'Not Found'], 404)]);

        $health = app(MLForecastClient::class)->health();

        $this->assertFalse($health['reachable']);
        $this->assertSame('The ML service is running an older version without a health check. Restart it to load the latest code.', $health['error']);
    }

    public function test_every_call_sends_the_shared_token_when_one_is_configured(): void
    {
        config(['services.ml_forecast.token' => 'shared-secret']);
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        $this->fakeEveryEndpoint();

        $client = app(MLForecastClient::class);
        $client->forecast('1L', $target->toDateString());
        $client->health();
        $client->climatology();

        Http::assertSentCount(3);

        foreach (['predict', 'health', 'climatology'] as $endpoint) {
            Http::assertSent(fn (Request $request): bool => $request->url() === "http://ml-forecast.test/{$endpoint}"
                && $request->hasHeader('Authorization', 'Bearer shared-secret'));
        }
    }

    public function test_no_call_sends_an_authorization_header_when_no_token_is_configured(): void
    {
        config(['services.ml_forecast.token' => null]);
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        $this->fakeEveryEndpoint();

        $client = app(MLForecastClient::class);
        $client->forecast('1L', $target->toDateString());
        $client->health();
        $client->climatology();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
    }

    public function test_a_rejected_token_is_reported_as_unauthorized(): void
    {
        config(['services.ml_forecast.token' => 'stale-secret']);
        $target = CarbonImmutable::parse('2026-10-04');
        $this->createSkuHistory('1L', $target);
        Http::fake(['ml-forecast.test/*' => Http::response(['detail' => 'Missing or invalid API token'], 401)]);

        $client = app(MLForecastClient::class);
        $outcome = $client->forecast('1L', $target->toDateString());
        $health = $client->health();

        $this->assertSame(ForecastStatus::Unauthorized, $outcome->status);
        $this->assertSame(MLForecastClient::UNAUTHORIZED_MESSAGE, $outcome->message());
        $this->assertSame(['reachable' => false, 'models' => [], 'error' => MLForecastClient::UNAUTHORIZED_MESSAGE], $health);
        $this->assertNull($client->climatology());
        $this->assertDatabaseCount('forecast_records', 0);
    }

    private function fakeEveryEndpoint(): void
    {
        Http::fake([
            'ml-forecast.test/predict' => Http::response([
                'predicted_units_sold' => 875.5,
                'model_version' => '2026-10-01',
                'rmse_score' => 12.3456,
            ]),
            'ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => ['1L' => '2026-10-01'], 'failed' => []]),
            'ml-forecast.test/climatology' => Http::response(['models' => []]),
        ]);
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
