<?php

use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$operationsTimezone = rescue(
    fn (): string => (string) app(SettingsService::class)->get('organisation.timezone', config('app.timezone')),
    fn (): string => (string) config('app.timezone'),
    report: false,
);

Schedule::command('errors:prune')->daily();

// Keep the next 30 days of forecast context in step with the Business Calendar (holidays, terms, promotions).
Schedule::command('calendar:rebuild')->dailyAt('00:30')->timezone($operationsTimezone);

// Close today's business day before the forecast run: tomorrow's forecast needs today covered.
// --catch-up also re-checks earlier days in the readiness window that a late order left uncovered, so they
// close once that order is delivered or cancelled. (Run by hand, sales:close-day defaults to yesterday.)
Schedule::command('sales:close-day', [CarbonImmutable::now($operationsTimezone)->toDateString(), '--catch-up'])
    ->dailyAt('17:45')
    ->timezone($operationsTimezone);
Schedule::command('forecasts:generate')->dailyAt('18:00')->timezone($operationsTimezone);
Schedule::command('invoices:notify-overdue')->dailyAt('08:00')->timezone($operationsTimezone);
Schedule::command('requests:notify-overdue')->dailyAt('08:05')->timezone($operationsTimezone);
Schedule::command('maintenance:generate-work-orders')->dailyAt('06:00')->timezone($operationsTimezone);
