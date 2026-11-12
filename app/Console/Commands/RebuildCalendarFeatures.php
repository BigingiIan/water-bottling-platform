<?php

namespace App\Console\Commands;

use App\Services\Calendar\ContextCalendar;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('calendar:rebuild {from? : Y-m-d, defaults to today} {to? : Y-m-d, defaults to 30 days after from} {--include-covered : Also rewrite days already used as sales history}')]
#[Description('Rewrite the forecast context features (promotion, holiday, school term, temperature) from the Business Calendar')]
class RebuildCalendarFeatures extends Command
{
    public function handle(ContextCalendar $calendar, SettingsService $settings): int
    {
        $today = CarbonImmutable::now((string) $settings->get('organisation.timezone', config('app.timezone')))->startOfDay();
        $from = $this->argument('from') === null ? $today : $this->parseDate($this->argument('from'));
        $to = $this->argument('to') === null ? $from?->addDays(30) : $this->parseDate($this->argument('to'));

        if ($from === null || $to === null) {
            $this->error('Dates must be valid Y-m-d values.');

            return self::INVALID;
        }

        if ($to->lessThan($from)) {
            $this->error('The end date must be on or after the start date.');

            return self::INVALID;
        }

        $result = $calendar->rebuild($from, $to, includeCovered: (bool) $this->option('include-covered'));

        $this->info("Rebuilt {$result['written']} feature ".str('row')->plural($result['written'])." for {$from->toDateString()} to {$to->toDateString()}.");

        if ($result['skippedCovered'] > 0) {
            $this->line("Left {$result['skippedCovered']} covered history ".str('row')->plural($result['skippedCovered']).' unchanged. Use --include-covered to rewrite them.');
        }

        foreach ($result['missingTemperature'] as $sku => $reason) {
            $this->warn("{$sku}: no temperature. {$reason}");
        }

        return self::SUCCESS;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }
}
