<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    /**
     * Seed test accounts for each application role.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@aquaops.test', 'role' => 'administrator'],
            ['name' => 'Production Manager', 'email' => 'prodmgr@aquaops.test', 'role' => 'production-manager'],
            ['name' => 'Production Staff', 'email' => 'prodstaff@aquaops.test', 'role' => 'production-staff'],
            ['name' => 'Inventory Staff', 'email' => 'inventory@aquaops.test', 'role' => 'inventory-staff'],
            ['name' => 'Maintenance Staff', 'email' => 'maintenance@aquaops.test', 'role' => 'maintenance-staff'],
        ];

        foreach ($users as $user) {
            $role = Role::query()->where('slug', $user['role'])->firstOrFail();

            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password_hash' => Hash::make('password'),
                    'role_id' => $role->id,
                    'status' => 'active',
                ],
            );
        }
    }
}
