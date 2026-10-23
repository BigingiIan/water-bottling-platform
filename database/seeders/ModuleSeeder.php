<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            ['name' => 'Dashboard', 'slug' => 'dashboard', 'description' => 'KPI overview'],
            ['name' => 'Production', 'slug' => 'production', 'description' => 'Schedule and runs'],
            ['name' => 'Inventory', 'slug' => 'inventory', 'description' => 'Stock and requisitions'],
            ['name' => 'Orders', 'slug' => 'orders', 'description' => 'Customer and delivery'],
            ['name' => 'Equipment', 'slug' => 'equipment', 'description' => 'Maintenance tasks'],
            ['name' => 'Forecasting', 'slug' => 'forecasting', 'description' => 'Demand prediction'],
            ['name' => 'Users & Roles', 'slug' => 'users-roles', 'description' => 'RBAC administration'],
            ['name' => 'Settings', 'slug' => 'settings', 'description' => 'Application settings and audit trail'],
            ['name' => 'Error Logs', 'slug' => 'error-logs', 'description' => 'Branded application errors'],
            ['name' => 'Invoicing', 'slug' => 'invoicing', 'description' => 'Invoices and customer payments'],
            ['name' => 'Revenue', 'slug' => 'revenue', 'description' => 'Invoiced, collected and outstanding revenue'],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['slug' => $module['slug']],
                ['name' => $module['name'], 'description' => $module['description']],
            );
        }
    }
}
