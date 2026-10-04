<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();

        DB::table('production_lines')->upsert(
            [
                ['name' => 'Line 01', 'status' => 'active', 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['name' => 'Line 02', 'status' => 'active', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ],
            ['name'],
            ['status', 'updated_at'],
        );
    }
}
