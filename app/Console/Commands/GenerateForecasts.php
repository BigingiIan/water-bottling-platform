<?php

namespace App\Console\Commands;

use App\Services\Forecasting\ForecastGenerator;
use App\Services\Forecasting\ForecastOutcome;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecasts:generate')]
#[Description("Request tomorrow's demand forecast for every SKU from the ML service")]
class GenerateForecasts extends Command
{
    public function handle(ForecastGenerator $generator): int
    {
        $outcomes = $generator->generateTomorrow();

        $this->table(
            ['SKU', 'Status', 'Result'],
            $outcomes->map(fn (ForecastOutcome $outcome): array => [
                $outcome->sku,
                $outcome->status->value,
                $outcome->message(),
            ])->values()->all(),
        );

        $generated = $outcomes->filter(fn (ForecastOutcome $outcome): bool => $outcome->isAvailable())->count();
        $this->info("Generated {$generated} of {$outcomes->count()} forecasts.");

        return self::SUCCESS;
    }
}
