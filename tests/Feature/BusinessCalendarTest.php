<?php

namespace Tests\Feature;

use App\Livewire\CalendarPage;
use App\Livewire\ForecastingPage;
use App\Models\AuditLog;
use App\Models\DailySkuFeature;
use App\Models\Module;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\SchoolTerm;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\ContextCalendar;
use App\Services\Calendar\MonthlyMeanTemperature;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Product $oneLitre;

    private Product $fiveLitre;

    private User $manager;

    private string $modelDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        config(['services.ml_forecast.url' => 'http://ml-forecast.test']);
        $this->modelDirectory = storage_path('framework/testing/calendar-manifests');
        config(['services.ml_forecast.model_dir' => $this->modelDirectory]);

        $this->oneLitre = Product::factory()->create(['unit_type' => '1L', 'name' => 'Bottled Water 1L']);
        $this->fiveLitre = Product::factory()->create(['unit_type' => '5L', 'name' => 'Bottled Water 5L']);
        $this->manager = $this->userWithRole('production-manager');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modelDirectory);

        parent::tearDown();
    }

    public function test_calendar_permissions_give_managers_full_access_and_everyone_else_view(): void
    {
        $levels = DB::table('role_module_permissions')
            ->join('roles', 'roles.id', '=', 'role_module_permissions.role_id')
            ->where('module_id', Module::query()->where('slug', 'calendar')->value('id'))
            ->pluck('access_level', 'roles.slug')
            ->all();

        $this->assertSame([
            'administrator' => 'full',
            'inventory-staff' => 'view',
            'maintenance-staff' => 'view',
            'production-manager' => 'full',
            'production-staff' => 'view',
        ], collect($levels)->sortKeys()->all());

        $this->actingAs($this->userWithRole('inventory-staff'));
        $this->get(route('calendar.index'))->assertOk()->assertDontSee(__('calendar.add.promotion'));
        Livewire::test(CalendarPage::class)->call('openCreate', 'promotion')->assertForbidden();

        $this->actingAs($this->manager);
        $this->get(route('calendar.index'))->assertOk()->assertSee(__('calendar.add.promotion'));
    }

    public function test_recurring_holidays_apply_every_year_and_one_off_holidays_only_on_their_date(): void
    {
        app(PublicHolidaySeeder::class)->run();
        PublicHoliday::factory()->create(['name' => 'Eid al-Fitr', 'date' => '2026-03-20', 'recurring' => false]);

        $this->rebuild('2026-01-01', '2027-12-31');

        $this->assertTrue($this->feature('1L', '2026-05-01')->is_public_holiday);
        $this->assertTrue($this->feature('1L', '2027-05-01')->is_public_holiday);
        $this->assertTrue($this->feature('5L', '2026-12-26')->is_public_holiday);
        $this->assertTrue($this->feature('1L', '2026-03-20')->is_public_holiday);
        $this->assertFalse($this->feature('1L', '2027-03-20')->is_public_holiday, 'A one-off holiday does not repeat.');
        $this->assertFalse($this->feature('1L', '2026-04-03')->is_public_holiday, 'Movable holidays are not guessed.');
        $this->assertSame(8, PublicHoliday::query()->where('recurring', true)->count());

        app(PublicHolidaySeeder::class)->run();
        $this->assertSame(8, PublicHoliday::query()->where('recurring', true)->count(), 'Reseeding does not duplicate.');
    }

    public function test_product_specific_and_all_product_promotions_set_the_right_flags_and_overlaps_are_one_flag(): void
    {
        // The nightly rebuild has already written every product's rows for these days.
        $this->rebuild('2026-11-01', '2026-11-10');
        $service = app(CalendarService::class);
        $oneLitreOnly = $service->savePromotion($this->manager, null, $this->promotionInput(['product_id' => $this->oneLitre->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-06']));
        $everyone = $service->savePromotion($this->manager, null, $this->promotionInput(['start_date' => '2026-11-05', 'end_date' => '2026-11-08']));

        $this->assertTrue($this->feature('1L', '2026-11-02')->is_promotion);
        $this->assertFalse($this->feature('5L', '2026-11-02')->is_promotion);
        $this->assertTrue($this->feature('1L', '2026-11-05')->is_promotion, 'Overlapping promotions are still one flag.');
        $this->assertTrue($this->feature('5L', '2026-11-08')->is_promotion);
        $this->assertFalse($this->feature('5L', '2026-11-09')->is_promotion);

        $service->delete($this->manager, $everyone);

        $this->assertTrue($this->feature('1L', '2026-11-05')->is_promotion, 'The remaining promotion still applies.');
        $this->assertFalse($this->feature('5L', '2026-11-05')->is_promotion);
        $this->assertFalse($this->feature('5L', '2026-11-08')->is_promotion);

        $service->savePromotion($this->manager, $oneLitreOnly, $this->promotionInput(['product_id' => $this->fiveLitre->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-06']));

        $this->assertFalse($this->feature('1L', '2026-11-02')->is_promotion, 'Moving a promotion to another product clears the old one.');
        $this->assertTrue($this->feature('5L', '2026-11-02')->is_promotion);
    }

    public function test_promotion_status_is_computed_from_its_dates(): void
    {
        $promotion = Promotion::factory()->make(['start_date' => '2026-10-20', 'end_date' => '2026-10-25']);

        $this->assertSame('planned', $promotion->status(CarbonImmutable::parse('2026-10-19')));
        $this->assertSame('active', $promotion->status(CarbonImmutable::parse('2026-10-25')));
        $this->assertSame('ended', $promotion->status(CarbonImmutable::parse('2026-10-26')));
    }

    public function test_rebuild_writes_school_terms_and_leaves_covered_history_alone_unless_asked(): void
    {
        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-30']);
        Promotion::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        DailySkuFeature::query()->create([
            'product_sku' => '1L', 'date' => '2026-10-20', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => false, 'temperature_c' => 30, 'sales_data_covered' => true,
        ]);

        $result = app(ContextCalendar::class)->rebuild(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-11-02'));

        $this->assertSame(1, $result['skippedCovered']);
        $this->assertSame(29, $result['written']);
        $this->assertTrue($this->feature('1L', '2026-10-30')->is_school_term);
        $this->assertFalse($this->feature('1L', '2026-10-31')->is_school_term);
        $this->assertFalse($this->feature('1L', '2026-10-20')->is_promotion, 'Covered history is not rewritten.');
        $this->assertTrue($this->feature('1L', '2026-10-20')->sales_data_covered);

        app(ContextCalendar::class)->rebuild(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-20'), includeCovered: true);

        $this->assertTrue($this->feature('1L', '2026-10-20')->is_promotion);
        $this->assertTrue($this->feature('1L', '2026-10-20')->sales_data_covered, 'Coverage itself is never changed by a rebuild.');
    }

    public function test_rebuild_command_defaults_to_the_next_30_days_and_rejects_bad_dates(): void
    {
        $this->artisan('calendar:rebuild')
            ->expectsOutputToContain('Rebuilt 62 feature rows for 2026-10-22 to 2026-11-21.')
            ->assertSuccessful();
        $this->artisan('calendar:rebuild', ['from' => '2026-02-30'])->assertExitCode(2);
        $this->artisan('calendar:rebuild', ['from' => '2026-10-10', 'to' => '2026-10-01'])->assertExitCode(2);

        $this->assertSame(62, DailySkuFeature::query()->count());
    }

    public function test_editing_entries_that_touch_covered_history_needs_full_access_a_reason_and_an_audit_entry(): void
    {
        $holiday = PublicHoliday::factory()->create(['name' => 'Founders Day', 'date' => '2026-10-15', 'recurring' => false]);
        DailySkuFeature::query()->create([
            'product_sku' => '1L', 'date' => '2026-10-15', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => false, 'temperature_c' => 25, 'sales_data_covered' => true,
        ]);
        $service = app(CalendarService::class);

        try {
            $service->saveHoliday($this->userWithRole('inventory-staff'), $holiday, ['name' => 'Founders Day', 'date' => '2026-10-15', 'recurring' => false], 'Fixing history');
            $this->fail('View access must not edit the calendar.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $service->saveHoliday($this->manager, $holiday, ['name' => 'Founders Day (observed)', 'date' => '2026-10-15', 'recurring' => false]);
            $this->fail('A reason must be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
            $this->assertStringContainsString('1 day of sales history', $exception->errors()['reason'][0]);
        }

        $this->assertFalse($this->feature('1L', '2026-10-15')->is_public_holiday);
        $this->assertSame('Founders Day', $holiday->refresh()->name);

        $service->saveHoliday($this->manager, $holiday, ['name' => 'Founders Day (observed)', 'date' => '2026-10-15', 'recurring' => false], 'Holiday was gazetted late');

        $this->assertTrue($this->feature('1L', '2026-10-15')->is_public_holiday);
        $audit = AuditLog::query()->where('action', 'calendar.history_changed')->sole();
        $this->assertSame($this->manager->id, $audit->user_id);
        $this->assertSame('Holiday was gazetted late', $audit->new_values['reason']);
        $this->assertSame(1, $audit->new_values['history_days']);
        $this->assertSame('Founders Day', $audit->old_values['name']);
    }

    public function test_future_only_changes_need_no_reason_but_are_still_audited(): void
    {
        app(CalendarService::class)->savePromotion($this->manager, null, $this->promotionInput(['start_date' => '2026-11-02', 'end_date' => '2026-11-03']));

        $this->assertDatabaseHas('audit_log', ['action' => 'calendar.promotion_created', 'user_id' => $this->manager->id]);
        $this->assertDatabaseMissing('audit_log', ['action' => 'calendar.history_changed']);
    }

    public function test_a_product_specific_promotion_only_counts_that_products_history(): void
    {
        DailySkuFeature::query()->create([
            'product_sku' => '5L', 'date' => '2026-10-10', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => false, 'temperature_c' => 25, 'sales_data_covered' => true,
        ]);

        app(CalendarService::class)->savePromotion($this->manager, null, $this->promotionInput(['product_id' => $this->oneLitre->id, 'start_date' => '2026-10-09', 'end_date' => '2026-10-11']));

        $this->assertDatabaseMissing('audit_log', ['action' => 'calendar.history_changed']);
        $this->assertFalse($this->feature('5L', '2026-10-10')->is_promotion);
    }

    public function test_the_calendar_page_asks_for_a_reason_when_a_change_touches_history(): void
    {
        DailySkuFeature::query()->create([
            'product_sku' => '1L', 'date' => '2026-10-10', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => false, 'temperature_c' => 25, 'sales_data_covered' => true,
        ]);
        $this->actingAs($this->manager);

        Livewire::test(CalendarPage::class, ['activeTab' => 'promotions'])
            ->call('openCreate', 'promotion')
            ->assertSee(__('calendar.promotion_note'))
            ->set('form.name', 'October bundle')
            ->set('form.start_date', '2026-10-09')
            ->set('form.end_date', '2026-10-11')
            ->call('save')
            ->assertHasErrors('reason')
            ->assertSet('needsReason', true)
            ->assertSee(__('calendar.fields.reason'))
            ->set('reason', 'Bundle ran but was not recorded')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingEntity', null);

        $this->assertTrue($this->feature('1L', '2026-10-10')->is_promotion);
        $this->assertDatabaseHas('audit_log', ['action' => 'calendar.history_changed']);

        Livewire::test(CalendarPage::class, ['activeTab' => 'terms'])
            ->call('openCreate', 'school_term')
            ->set('form.year', 2026)
            ->set('form.term', 3)
            ->set('form.start_date', '2025-12-01')
            ->set('form.end_date', '2026-01-10')
            ->call('save')
            ->assertHasErrors('form.start_date');
    }

    public function test_temperatures_come_from_the_service_climatology_and_fall_back_to_the_manifests(): void
    {
        Http::fake(['ml-forecast.test/climatology' => Http::response(['models' => [
            '1L' => ['temperature_source' => 'monthly_mean', 'months' => array_combine(array_map('strval', range(1, 12)), array_map(fn (int $month): float => 20 + $month / 10, range(1, 12)))],
        ]])]);
        $this->writeManifest('5L', 19.5);

        $result = $this->rebuild('2026-11-01', '2026-11-01');

        $this->assertSame(21.1, $this->feature('1L', '2026-11-01')->temperature_c);
        $this->assertSame('monthly_mean', $this->feature('1L', '2026-11-01')->temperature_source);
        $this->assertSame(19.5, $this->feature('5L', '2026-11-01')->temperature_c, 'Missing from the service, read from the manifest.');
        $this->assertSame([], $result['missingTemperature']);

        $this->app->forgetScopedInstances();
        $this->rebuild('2026-12-01', '2026-12-01');
        Http::assertSentCount(1);
        $this->assertTrue(cache()->has(MonthlyMeanTemperature::CACHE_KEY));
    }

    public function test_missing_temperatures_are_reported_and_existing_values_kept(): void
    {
        Http::fake(['ml-forecast.test/climatology' => Http::failedConnection()]);
        DailySkuFeature::query()->create([
            'product_sku' => '1L', 'date' => '2026-11-01', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => false, 'temperature_c' => 24, 'sales_data_covered' => false,
        ]);

        $this->artisan('calendar:rebuild', ['from' => '2026-11-01', 'to' => '2026-11-01'])
            ->expectsOutputToContain('1L: no temperature. Model manifest not found for 1L')
            ->assertSuccessful();

        $this->assertSame(24.0, $this->feature('1L', '2026-11-01')->temperature_c);
    }

    public function test_forecasting_page_warns_when_school_terms_are_missing_and_shows_calendar_chips(): void
    {
        Http::fake([
            'ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => []]),
            'ml-forecast.test/climatology' => Http::response(['models' => []]),
        ]);
        Promotion::factory()->create(['name' => 'Weekend deal', 'product_id' => $this->oneLitre->id, 'start_date' => '2026-10-23', 'end_date' => '2026-10-24']);
        PublicHoliday::factory()->create(['name' => 'Founders Day', 'date' => '2026-10-26']);
        $this->actingAs($this->userWithRole('administrator'));

        $component = Livewire::test(ForecastingPage::class)
            ->assertSee(__('calendar.missing_terms', ['years' => '2026']))
            ->assertSee('Promotion planned · tomorrow')
            ->assertSee('Public holiday: Founders Day · Mon 26 Oct');

        $chips = $component->viewData('calendarChips');
        $this->assertSame(['promotion', 'promotion', 'holiday'], array_column($chips[$this->oneLitre->id], 'kind'));
        $this->assertSame(['holiday'], array_column($chips[$this->fiveLitre->id], 'kind'));

        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-30']);

        Livewire::test(ForecastingPage::class)->assertDontSee(__('calendar.missing_terms', ['years' => '2026']));
    }

    public function test_forecasting_page_shows_the_temperature_source(): void
    {
        Http::fake(['ml-forecast.test/health' => Http::response(['status' => 'ok', 'models' => []])]);
        DailySkuFeature::query()->create([
            'product_sku' => '1L', 'date' => '2026-10-23', 'is_promotion' => false, 'is_public_holiday' => false,
            'is_school_term' => true, 'temperature_c' => 25, 'temperature_source' => 'monthly_mean', 'sales_data_covered' => false,
        ]);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(ForecastingPage::class)->assertSee('Temperature: monthly average from the model');
    }

    public function test_month_view_shows_holidays_terms_and_promotions_as_labelled_bands(): void
    {
        app(PublicHolidaySeeder::class)->run();
        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-30']);
        Promotion::factory()->create(['name' => 'Mashujaa bundle', 'product_id' => $this->oneLitre->id, 'start_date' => '2026-10-19', 'end_date' => '2026-10-21']);
        $this->actingAs($this->userWithRole('maintenance-staff'));

        Livewire::test(CalendarPage::class)
            ->assertSee('October 2026')
            ->assertSee('Mashujaa Day')
            ->assertSee('Term 3')
            ->assertSee('Mashujaa bundle · 1L')
            ->call('showMonth', '2026-12')
            ->assertSee('December 2026')
            ->assertSee('Jamhuri Day')
            ->assertSee('Christmas Day');
    }

    /**
     * @return array{written: int, skippedCovered: int, missingTemperature: array<string, string>}
     */
    private function rebuild(string $from, string $to): array
    {
        return app(ContextCalendar::class)->rebuild(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    private function feature(string $sku, string $date): DailySkuFeature
    {
        return DailySkuFeature::query()->where('product_sku', $sku)->whereDate('date', $date)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function promotionInput(array $overrides = []): array
    {
        return [
            'name' => 'Promotion',
            'product_id' => null,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-03',
            'type' => 'price_discount',
            'discount_percent' => '10',
            'notes' => null,
            ...$overrides,
        ];
    }

    private function writeManifest(string $sku, float $temperature): void
    {
        File::ensureDirectoryExists("{$this->modelDirectory}/{$sku}");
        File::put("{$this->modelDirectory}/{$sku}/manifest.json", json_encode([
            'temperature_climatology' => array_fill_keys(array_map('strval', range(1, 12)), $temperature),
        ]));
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id')]);
    }
}
