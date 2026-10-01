<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'Administrator', 'slug' => 'administrator'],
            ['name' => 'Production Manager', 'slug' => 'production-manager'],
            ['name' => 'Production Staff', 'slug' => 'production-staff'],
            ['name' => 'Inventory Staff', 'slug' => 'inventory-staff'],
            ['name' => 'Maintenance Staff', 'slug' => 'maintenance-staff'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                ['name' => $role['name']],
            );
        }
    }
}
