<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_administrator_and_audits_it_without_the_password(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', 'Amina Otieno')
            ->expectsQuestion('Email address', 'amina@example.test')
            ->expectsQuestion('Password', 'a-strong-password-1')
            ->expectsQuestion('Confirm password', 'a-strong-password-1')
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'amina@example.test')->firstOrFail();

        $this->assertSame('Amina Otieno', $administrator->name);
        $this->assertSame('administrator', $administrator->role->slug);
        $this->assertSame('active', $administrator->status);
        $this->assertFalse($administrator->must_change_password);
        $this->assertNull($administrator->temporary_password_expires_at);
        $this->assertTrue(Hash::check('a-strong-password-1', $administrator->password_hash));

        $audit = AuditLog::query()->where('action', 'user.created')->sole();

        $this->assertNull($audit->user_id);
        $this->assertSame($administrator->id, $audit->target_user_id);
        $this->assertSame('administrator', $audit->new_values['role']);
        $this->assertStringNotContainsString('a-strong-password-1', (string) json_encode($audit->getAttributes()));

        $this->post('/login', ['email' => 'amina@example.test', 'password' => 'a-strong-password-1'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_name_and_email_can_be_given_as_options(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('app:create-admin', ['--name' => 'Amina Otieno', '--email' => 'amina@example.test'])
            ->expectsQuestion('Password', 'a-strong-password-1')
            ->expectsQuestion('Confirm password', 'a-strong-password-1')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'amina@example.test', 'status' => 'active']);
    }

    public function test_it_fails_clearly_when_roles_are_not_seeded(): void
    {
        Role::query()->delete();

        $this->artisan('app:create-admin', ['--name' => 'Amina Otieno', '--email' => 'amina@example.test'])
            ->expectsOutputToContain('php artisan db:seed --force')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_a_duplicate_email(): void
    {
        $this->seed(RoleSeeder::class);
        User::factory()->create(['email' => 'amina@example.test']);

        $this->artisan('app:create-admin', ['--name' => 'Amina Otieno', '--email' => 'amina@example.test'])
            ->expectsOutputToContain('A user with this email address already exists.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_rejects_a_mismatched_confirmation(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('app:create-admin', ['--name' => 'Amina Otieno', '--email' => 'amina@example.test'])
            ->expectsQuestion('Password', 'a-strong-password-1')
            ->expectsQuestion('Confirm password', 'a-different-password-1')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_enforces_the_configured_minimum_password_length(): void
    {
        $this->seed(RoleSeeder::class);
        app(SettingsService::class)->set('security.password_min_length', 16, User::factory()->create());

        $this->artisan('app:create-admin', ['--name' => 'Amina Otieno', '--email' => 'amina@example.test'])
            ->expectsQuestion('Password', 'TwelveChars1!')
            ->expectsQuestion('Confirm password', 'TwelveChars1!')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'amina@example.test']);
    }
}
