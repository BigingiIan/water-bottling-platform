<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
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

    public function test_guest_is_redirected_to_login_and_can_open_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('Access is managed by your AquaOps administrator.')
            ->assertDontSee('register', false)
            ->assertDontSee('id="main-sidebar"', false);
    }

    public function test_active_user_can_sign_in_and_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.test',
            'password_hash' => bcrypt('secret-password'),
            'status' => 'active',
        ]);

        $this->withSession(['url.intended' => '/staff'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee($user->name);
    }

    public function test_suspended_user_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.test',
            'password_hash' => bcrypt('secret-password'),
            'status' => 'suspended',
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_invalid_password_does_not_authenticate_user(): void
    {
        $user = User::factory()->create([
            'password_hash' => bcrypt('secret-password'),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_route_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_authenticated_user_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create([
            'email' => 'limit@example.test',
            'password_hash' => bcrypt('secret-password'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
