<?php

namespace Tests\Feature;

use App\Livewire\ErrorLogs;
use App\Models\ErrorEvent;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorLogsTest extends TestCase
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

    public function test_user_without_error_logs_access_is_forbidden_from_viewer(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $productionStaff->id]));

        $this->get(route('errors.index'))->assertForbidden();
    }

    public function test_custom_role_with_error_logs_access_can_open_viewer(): void
    {
        $role = Role::query()->create([
            'name' => 'Incident Reviewer',
            'slug' => 'incident-reviewer',
        ]);
        $module = Module::query()->where('slug', 'error-logs')->firstOrFail();
        DB::table('role_module_permissions')->insert([
            'role_id' => $role->id,
            'module_id' => $module->id,
            'access_level' => 'view',
        ]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->get(route('errors.index'))
            ->assertOk()
            ->assertSee('Error Logs')
            ->assertSee(route('errors.index'));
    }

    public function test_viewer_filters_events_and_opens_event_details(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $firstUser = User::factory()->create([
            'name' => 'First Reporter',
            'role_id' => $administrator->id,
        ]);
        $secondUser = User::factory()->create([
            'name' => 'Second Reporter',
            'role_id' => $administrator->id,
        ]);
        $firstEvent = $this->createEvent([
            'reference' => 'ABCD-1234',
            'user_id' => $firstUser->id,
            'status_code' => 403,
            'module_slug' => 'inventory',
            'path' => '/inventory',
            'method' => 'GET',
            'exception_class' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'message' => 'Access denied.',
            'exception_message' => 'Access denied.',
            'created_at' => '2026-10-03 12:00:00',
        ]);
        $secondEvent = $this->createEvent([
            'reference' => 'EFGH-5678',
            'user_id' => $secondUser->id,
            'status_code' => 500,
            'module_slug' => 'production',
            'path' => '/production',
            'method' => 'POST',
            'exception_class' => 'RuntimeException',
            'message' => 'An unexpected server error occurred.',
            'exception_message' => 'Private failure details.',
            'created_at' => '2026-10-02 12:00:00',
        ]);
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));

        Livewire::test(ErrorLogs::class)
            ->assertSee($firstEvent->reference)
            ->assertSee($secondEvent->reference)
            ->set('statusFilter', '403')
            ->set('userFilter', (string) $firstUser->id)
            ->set('moduleFilter', 'inventory')
            ->set('startDate', '2026-10-03')
            ->set('endDate', '2026-10-03')
            ->set('referenceSearch', 'ABCD')
            ->assertSee($firstEvent->reference)
            ->assertDontSee($secondEvent->reference)
            ->call('showDetails', $firstEvent->id)
            ->assertSee('Error details · ABCD-1234')
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('keydown.escape')
            ->assertSee('Copy reference')
            ->assertSee('First Reporter')
            ->assertSee('Access denied.')
            ->assertSee('GET /inventory');
    }

    public function test_viewer_can_clear_filter_selections(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $event = $this->createEvent(['reference' => 'ABCD-1234', 'status_code' => 500]);
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));

        Livewire::test(ErrorLogs::class)
            ->set('statusFilter', '403')
            ->assertDontSee($event->reference)
            ->set('statusFilter', '')
            ->assertHasNoErrors()
            ->assertSee($event->reference);
    }

    public function test_prune_command_deletes_only_events_older_than_thirty_days(): void
    {
        $this->freezeTime();
        $oldEvent = $this->createEvent(['created_at' => now()->subDays(31)]);
        $boundaryEvent = $this->createEvent(['created_at' => now()->subDays(30)]);
        $recentEvent = $this->createEvent(['created_at' => now()->subDays(1)]);

        $this->artisan('errors:prune')
            ->expectsOutput('Pruned 1 error events.')
            ->assertSuccessful();

        $this->assertModelMissing($oldEvent);
        $this->assertModelExists($boundaryEvent);
        $this->assertModelExists($recentEvent);
    }

    public function test_view_access_only_sees_exception_class_and_reference_in_details(): void
    {
        $role = Role::query()->create([
            'name' => 'Incident Reviewer',
            'slug' => 'incident-reviewer',
        ]);
        $module = Module::query()->where('slug', 'error-logs')->firstOrFail();
        DB::table('role_module_permissions')->insert([
            'role_id' => $role->id,
            'module_id' => $module->id,
            'access_level' => 'view',
        ]);
        $event = $this->createEvent([
            'reference' => 'VIEW-1234',
            'exception_class' => RuntimeException::class,
            'exception_message' => 'secret exception message',
            'exception_file' => 'C:\\private\\application.php',
            'exception_line' => 42,
            'trace' => [['file' => 'C:\\private\\trace.php', 'line' => 10]],
            'ip_address' => '192.0.2.20',
            'resolution_note' => 'secret resolution note',
        ]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        Livewire::test(ErrorLogs::class)
            ->call('showDetails', $event->id)
            ->assertSet('resolutionNote', '')
            ->assertSee('VIEW-1234')
            ->assertSee(RuntimeException::class)
            ->assertDontSee('secret exception message')
            ->assertDontSee('private')
            ->assertDontSee('192.0.2.20')
            ->assertDontSee('secret resolution note')
            ->assertDontSee('Exception message')
            ->assertDontSee('Trace (top 15 frames)')
            ->call('updateResolution', true)
            ->assertForbidden();
    }

    public function test_full_access_can_resolve_event_with_optional_note(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $event = $this->createEvent(['reference' => 'FULL-1234']);
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));

        Livewire::test(ErrorLogs::class)
            ->call('showDetails', $event->id)
            ->set('resolutionNote', 'Fixed by restoring the missing route.')
            ->call('updateResolution', true)
            ->assertSee('Resolved');

        $event->refresh();
        $this->assertNotNull($event->resolved_at);
        $this->assertSame('Fixed by restoring the missing route.', $event->resolution_note);
    }

    public function test_grouped_view_aggregates_fingerprint_events_and_toggles_to_raw_events(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->createEvent([
            'reference' => 'GRP1-0001',
            'fingerprint' => str_repeat('a', 64),
            'exception_class' => 'App\\Exceptions\\RepeatedFailure',
            'created_at' => '2026-10-01 12:00:00',
        ]);
        $this->createEvent([
            'reference' => 'GRP1-0002',
            'fingerprint' => str_repeat('a', 64),
            'exception_class' => 'App\\Exceptions\\RepeatedFailure',
            'created_at' => '2026-10-03 12:00:00',
        ]);
        $this->createEvent([
            'reference' => 'GRP2-0001',
            'fingerprint' => str_repeat('b', 64),
            'exception_class' => 'App\\Exceptions\\SingleFailure',
            'created_at' => '2026-10-02 12:00:00',
        ]);
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));

        Livewire::test(ErrorLogs::class)
            ->call('showGroups')
            ->assertSee('App\\Exceptions\\RepeatedFailure')
            ->assertSee('2')
            ->assertSee('01 Oct 2026, 15:00')
            ->assertSee('03 Oct 2026, 15:00')
            ->assertSee('App\\Exceptions\\SingleFailure')
            ->call('showGroupDetails', str_repeat('a', 64))
            ->assertSee('GRP1-0002')
            ->call('closeDetails')
            ->call('showEvents')
            ->assertSee('GRP1-0001')
            ->assertSee('GRP1-0002');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEvent(array $attributes = []): ErrorEvent
    {
        return ErrorEvent::query()->create([
            'reference' => sprintf('TEST-%04d', ErrorEvent::query()->count() + 1),
            'user_id' => null,
            'status_code' => 500,
            'module_slug' => null,
            'path' => '/test',
            'method' => 'GET',
            'exception_class' => null,
            'message' => 'Test error.',
            'exception_message' => 'Test exception details.',
            'created_at' => now(),
            ...$attributes,
        ]);
    }
}
