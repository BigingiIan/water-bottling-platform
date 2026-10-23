<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\AuditLog;
use App\Models\ErrorEvent;
use App\Models\Module;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\SettingsService;
use App\Support\LeakageReportFlag;
use App\Support\SettingsDate;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
    }

    public function test_administrator_can_open_settings_and_old_audit_route_redirects_to_audit_tab(): void
    {
        $administrator = $this->userWithRole('administrator');

        $this->actingAs($administrator)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('General')
            ->assertSee('AquaOps Water')
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertDontSee('Audit Logs');

        $this->get('/audit-logs')
            ->assertRedirect(route('settings.index', ['tab' => 'audit']));
    }

    public function test_none_access_is_forbidden_and_view_access_cannot_save(): void
    {
        $this->actingAs($this->userWithRole('production-staff'))
            ->get(route('settings.index'))
            ->assertForbidden();

        $role = Role::query()->create([
            'name' => 'Settings Reader',
            'slug' => 'settings-reader',
        ]);
        $settingsModule = Module::query()->where('slug', 'settings')->firstOrFail();
        DB::table('role_module_permissions')->insert([
            'role_id' => $role->id,
            'module_id' => $settingsModule->id,
            'access_level' => 'view',
        ]);
        $viewer = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('View-only access')
            ->assertDontSee('Save settings')
            ->assertDontSee('System error alerts');

        Livewire::test(SettingsPage::class)
            ->call('saveSettings')
            ->assertForbidden();
    }

    public function test_session_lifetime_setting_is_applied_before_session_start(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('security.session_minutes', 45, $administrator);

        $this->actingAs($administrator)
            ->get(route('settings.index'))
            ->assertOk();

        $this->assertSame(45, config('session.lifetime'));
    }

    public function test_password_set_uses_the_configured_minimum_length(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('security.password_min_length', 16, $administrator);
        $pendingUser = User::factory()->create([
            'status' => 'pending',
            'must_change_password' => true,
        ]);

        $this->actingAs($pendingUser)
            ->from(route('password.set'))
            ->post(route('password.set.update'), [
                'password' => 'TwelveChars1!',
                'password_confirmation' => 'TwelveChars1!',
            ])
            ->assertRedirect(route('password.set'))
            ->assertSessionHasErrors('password');
    }

    public function test_disabling_forecast_hides_inventory_suggestions(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('forecast.enabled', false, $administrator);

        $this->actingAs($administrator)
            ->get(route('inventory.overview'))
            ->assertOk()
            ->assertSee('disabled in Settings')
            ->assertDontSee('Suggested run:');
    }

    public function test_save_writes_override_and_audit_values_and_emits_success_toast(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->actingAs($administrator);

        Livewire::test(SettingsPage::class)
            ->set('values.organisation_name', 'AquaOps East Africa')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertDispatched('settings-saved', message: 'Settings saved.');

        $this->assertDatabaseHas('settings', [
            'group' => 'organisation',
            'key' => 'organisation.name',
            'value' => 'AquaOps East Africa',
            'type' => 'string',
            'updated_by' => $administrator->id,
        ]);

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(['key' => 'organisation.name', 'value' => 'AquaOps Water'], $audit->old_values);
        $this->assertSame(['key' => 'organisation.name', 'value' => 'AquaOps East Africa'], $audit->new_values);
    }

    public function test_reset_defaults_requires_confirmation_and_removes_override(): void
    {
        $administrator = $this->userWithRole('administrator');
        $settings = app(SettingsService::class);
        $settings->set('organisation.name', 'AquaOps East Africa', $administrator);
        $this->actingAs($administrator);

        Livewire::test(SettingsPage::class)
            ->call('requestResetDefaults')
            ->assertSet('showResetModal', true)
            ->assertSee('Reset General settings?')
            ->call('resetToDefaults')
            ->assertSet('showResetModal', false)
            ->assertDispatched('settings-saved', message: 'Defaults restored.');

        $this->assertDatabaseMissing('settings', ['key' => 'organisation.name']);
        $this->assertSame('AquaOps Water', $settings->get('organisation.name'));
        $this->assertDatabaseHas('audit_log', [
            'action' => 'settings.reset',
            'table_name' => 'settings',
            'user_id' => $administrator->id,
        ]);
    }

    #[DataProvider('invalidSettings')]
    public function test_settings_reject_invalid_field_values(string $tab, string $field, mixed $value): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(SettingsPage::class)
            ->set('activeTab', $tab)
            ->set('values.'.$field, $value)
            ->call('saveSettings')
            ->assertHasErrors('values.'.$field);
    }

    public static function invalidSettings(): array
    {
        return [
            'organisation name over maximum' => ['general', 'organisation_name', str_repeat('A', 81)],
            'invalid timezone' => ['general', 'organisation_timezone', 'Mars/Olympus'],
            'currency not three uppercase letters' => ['general', 'organisation_currency', 'K!S'],
            'unsupported date format' => ['general', 'ui_date_format', 'm/d/Y'],
            'invalid shift start time' => ['operations', 'production_shift_start', '7am'],
            'critical percentage below range' => ['operations', 'inventory_critical_percent', 9],
            'critical percentage above range' => ['operations', 'inventory_critical_percent', 91],
            'cover days below range' => ['operations', 'inventory_cover_warning_days', 0.4],
            'cover days above range' => ['operations', 'inventory_cover_warning_days', 14.1],
            'review percentage below range' => ['operations', 'leakage_review_percent', -0.1],
            'review percentage above range' => ['operations', 'leakage_review_percent', 100.1],
            'investigate percentage below range' => ['operations', 'leakage_investigate_percent', -0.1],
            'investigate percentage above range' => ['operations', 'leakage_investigate_percent', 100.1],
            'session minutes below range' => ['security', 'security_session_minutes', 14],
            'session minutes above range' => ['security', 'security_session_minutes', 1441],
            'password minimum below range' => ['security', 'security_password_min_length', 7],
            'password minimum above range' => ['security', 'security_password_min_length', 65],
            'retention below range' => ['security', 'retention_error_log_days', 6],
            'retention above range' => ['security', 'retention_error_log_days', 366],
        ];
    }

    public function test_investigate_threshold_must_exceed_review_threshold(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(SettingsPage::class)
            ->set('activeTab', 'operations')
            ->set('values.leakage_review_percent', 5)
            ->set('values.leakage_investigate_percent', 5)
            ->call('saveSettings')
            ->assertHasErrors('values.leakage_investigate_percent');
    }

    public function test_settings_service_overrides_stock_leakage_and_date_behaviour(): void
    {
        $administrator = $this->userWithRole('administrator');
        $settings = app(SettingsService::class);
        $settings->setMany([
            'inventory.critical_percent' => 80,
            'leakage.review_percent' => 3,
            'leakage.investigate_percent' => 8,
            'ui.date_format' => 'Y-m-d',
        ], $administrator);

        $product = Product::query()->create([
            'name' => 'Test water',
            'unit_type' => '500ml',
            'reorder_threshold' => 100,
        ]);
        $this->assertSame('critical', app(StockService::class)->statusForProduct($product, 70));
        $this->assertSame('Normal', LeakageReportFlag::fromVolumes(100, 98));
        $this->assertSame('Review', LeakageReportFlag::fromVolumes(100, 97));
        $this->assertSame('2026-10-22', app(SettingsDate::class)->formatDate('2026-10-22'));
        $this->assertSame('2026-10-23', app(SettingsDate::class)->formatInstantDate('2026-10-22 22:30:00'));
        $this->assertSame('2026-10-22, 03:00', app(SettingsDate::class)->formatDateTime('2026-10-22 00:00:00', 'H:i'));
        $this->assertSame('03:00', app(SettingsDate::class)->formatTime('2026-10-22 00:00:00'));
    }

    public function test_prune_command_uses_configured_error_log_retention_days(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('retention.error_log_days', 7, $administrator);
        $oldEvent = $this->createErrorEvent(now()->subDays(8));
        $boundaryEvent = $this->createErrorEvent(now()->subDays(7));

        $this->artisan('errors:prune')
            ->expectsOutput('Pruned 1 error events.')
            ->assertSuccessful();

        $this->assertModelMissing($oldEvent);
        $this->assertModelExists($boundaryEvent);
    }

    public function test_audit_trail_shows_newest_rows_and_filters_by_action(): void
    {
        $administrator = $this->userWithRole('administrator');
        $older = AuditLog::query()->create([
            'user_id' => $administrator->id,
            'action' => 'settings.test_old',
            'table_name' => 'settings',
        ]);
        $newer = AuditLog::query()->create([
            'user_id' => $administrator->id,
            'action' => 'settings.test_new',
            'table_name' => 'settings',
        ]);
        $this->actingAs($administrator);

        Livewire::test(SettingsPage::class)
            ->set('activeTab', 'audit')
            ->assertSeeInOrder(['settings.test_new', 'settings.test_old'])
            ->set('auditAction', 'settings.test_old')
            ->assertSee('settings.test_old')
            ->assertDontSee('>settings.test_new</td>', false);

        $this->assertModelExists($older);
        $this->assertModelExists($newer);
    }

    public function test_audit_trail_filters_by_user_and_date_range(): void
    {
        $administrator = $this->userWithRole('administrator');
        $otherUser = $this->userWithRole('production-staff');
        (new AuditLog)->forceFill([
            'user_id' => $administrator->id,
            'action' => 'settings.admin_entry',
            'table_name' => 'settings',
            'timestamp' => '2026-10-01 09:00:00',
        ])->save();
        (new AuditLog)->forceFill([
            'user_id' => $otherUser->id,
            'action' => 'settings.other_entry',
            'table_name' => 'settings',
            'timestamp' => '2026-10-15 09:00:00',
        ])->save();
        $this->actingAs($administrator);

        Livewire::test(SettingsPage::class)
            ->set('activeTab', 'audit')
            ->set('auditUser', (string) $otherUser->id)
            ->assertSee('>settings.other_entry</td>', false)
            ->assertDontSee('>settings.admin_entry</td>', false)
            ->set('auditUser', '')
            ->set('auditDateFrom', '2026-09-30')
            ->set('auditDateTo', '2026-10-02')
            ->assertSee('>settings.admin_entry</td>', false)
            ->assertDontSee('>settings.other_entry</td>', false);
    }

    private function userWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function createErrorEvent(\DateTimeInterface $createdAt): ErrorEvent
    {
        return ErrorEvent::query()->create([
            'reference' => sprintf('SET-%04d', ErrorEvent::query()->count() + 1),
            'status_code' => 500,
            'method' => 'GET',
            'path' => '/test',
            'message' => 'Test',
            'created_at' => $createdAt,
        ]);
    }
}
