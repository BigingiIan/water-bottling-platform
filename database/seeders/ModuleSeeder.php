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
            ['name' => 'Dashboard', 'slug' => 'dashboard', 'description' => "Home page with the day's key figures"],
            ['name' => 'Production', 'slug' => 'production', 'description' => 'Plan production, run lines and review history'],
            ['name' => 'Inventory', 'slug' => 'inventory', 'description' => 'Warehouse stock levels, counts and leakage reports'],
            ['name' => 'Orders', 'slug' => 'orders', 'description' => 'Customer orders and deliveries'],
            ['name' => 'Equipment', 'slug' => 'equipment', 'description' => 'Machine maintenance tasks'],
            ['name' => 'Forecasting', 'slug' => 'forecasting', 'description' => 'Predicted demand for each product'],
            ['name' => 'Users & Roles', 'slug' => 'users-roles', 'description' => 'Invite staff and decide what each role can access'],
            ['name' => 'Settings', 'slug' => 'settings', 'description' => 'Organisation settings and the change history'],
            ['name' => 'Error Logs', 'slug' => 'error-logs', 'description' => 'Errors the system has recorded'],
            ['name' => 'Invoicing', 'slug' => 'invoicing', 'description' => 'Raise invoices and record customer payments'],
            ['name' => 'Revenue', 'slug' => 'revenue', 'description' => 'Money invoiced, collected and still owed'],
            ['name' => 'Requests', 'slug' => 'requests', 'description' => 'Ask another team to act and follow up on what was asked of yours'],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['slug' => $module['slug']],
                ['name' => $module['name'], 'description' => $module['description']],
            );
        }
    }
}
