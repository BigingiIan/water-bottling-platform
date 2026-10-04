<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
            ProductionLineSeeder::class,
            DailySkuFeatureSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call(TestUserSeeder::class);
        }
    }
}
