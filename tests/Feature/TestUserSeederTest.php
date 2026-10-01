<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TestUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_active_users_with_hashed_passwords_and_matching_roles(): void
    {
        $this->seed();

        $users = User::query()->with('role')->orderBy('email')->get();

        $this->assertSame(
            [
                ['admin@aquaops.test', 'active', 'Administrator'],
                ['inventory@aquaops.test', 'active', 'Inventory Staff'],
                ['maintenance@aquaops.test', 'active', 'Maintenance Staff'],
                ['prodmgr@aquaops.test', 'active', 'Production Manager'],
                ['prodstaff@aquaops.test', 'active', 'Production Staff'],
            ],
            $users->map(fn (User $user): array => [$user->email, $user->status, $user->role->name])->all(),
        );

        foreach ($users as $user) {
            $this->assertTrue(Hash::check('password', $user->password_hash));
        }
    }

    public static function seededAccounts(): array
    {
        return [
            'administrator' => ['admin@aquaops.test'],
            'production manager' => ['prodmgr@aquaops.test'],
            'production staff' => ['prodstaff@aquaops.test'],
            'inventory staff' => ['inventory@aquaops.test'],
            'maintenance staff' => ['maintenance@aquaops.test'],
        ];
    }

    #[DataProvider('seededAccounts')]
    public function test_seeded_account_can_log_in(string $email): void
    {
        $this->seed();
        $user = User::query()->where('email', $email)->firstOrFail();

        $this->post('/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_database_seeder_does_not_create_test_users_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            app(DatabaseSeeder::class)->run();

            $this->assertDatabaseCount('users', 0);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
