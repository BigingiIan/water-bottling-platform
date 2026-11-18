<?php

namespace Database\Seeders;

use App\Services\Calendar\ContextCalendar;
use Illuminate\Database\Seeder;

class DailySkuFeatureSeeder extends Seeder
{
    /**
     * Writes context features from two years back to one year ahead from the Business Calendar.
     *
     * Days already marked as covered sales history are left untouched, so rerunning the seeder never
     * rewrites what the model has learned from.
     */
    public function run(): void
    {
        app(ContextCalendar::class)->rebuild(today()->subYears(2), today()->addYear());
    }
}
