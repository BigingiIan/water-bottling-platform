<?php

namespace App\Services\Forecasting;

use App\Support\Plural;
use App\Support\SettingsDate;

final readonly class ForecastOutcome
{
    /**
     * @param  array<int, string>  $missingDates  Y-m-d dates in the history window without confirmed coverage
     */
    public function __construct(
        public ForecastStatus $status,
        public string $sku,
        public string $targetDate,
        public ?float $prediction = null,
        public ?string $modelVersion = null,
        public ?float $rmseScore = null,
        public array $missingDates = [],
        public int $requiredDays = 0,
        public ?string $detail = null,
    ) {}

    public static function generated(string $sku, string $targetDate, float $prediction, string $modelVersion, float $rmseScore): self
    {
        return new self(ForecastStatus::Generated, $sku, $targetDate, $prediction, $modelVersion, $rmseScore);
    }

    public static function failed(ForecastStatus $status, string $sku, string $targetDate, ?string $detail = null): self
    {
        return new self($status, $sku, $targetDate, detail: $detail);
    }

    public function isAvailable(): bool
    {
        return $this->status->isAvailable();
    }

    /**
     * A sentence a planner can act on, explaining the forecast or why it is missing.
     */
    public function message(): string
    {
        $dates = app(SettingsDate::class);
        $targetDate = $dates->formatDate($this->targetDate);

        return match ($this->status) {
            ForecastStatus::Generated => number_format((float) $this->prediction).' units forecast for '.$targetDate.'.',
            ForecastStatus::InvalidDate => 'The forecast date is not a valid calendar date.',
            ForecastStatus::UnknownSku => "No product uses the SKU {$this->sku}.",
            ForecastStatus::MissingCoveredDays => count($this->missingDates) >= $this->requiredDays
                ? 'No confirmed sales coverage for the '.Plural::count($this->requiredDays, 'day').' before '.$targetDate.'. The model needs all of them.'
                : 'Missing '.count($this->missingDates).' of '.Plural::count($this->requiredDays, 'covered day')
                    .' before '.$targetDate.': '.$this->formattedMissingDates().'. Confirm sales coverage for these days.',
            ForecastStatus::MissingContext => 'No promotion, holiday, school-term and temperature context for '.$targetDate.'.',
            ForecastStatus::ServiceUnreachable => 'The ML service is unreachable'.($this->detail ? " at {$this->detail}" : '').'.',
            ForecastStatus::NoModelForSku => "The ML service has no trained model for {$this->sku}.",
            ForecastStatus::ServiceError => 'The ML service could not score this request'.($this->detail ? " ({$this->detail})" : '').'.',
            ForecastStatus::InvalidResponse => 'The ML service returned an invalid prediction or missing model metadata.',
            ForecastStatus::NotStored => 'The forecast was produced but could not be saved.',
            ForecastStatus::NotGenerated => 'No forecast has been generated for '.$targetDate.' yet.',
        };
    }

    private function formattedMissingDates(): string
    {
        $dates = app(SettingsDate::class);
        $shown = array_map(fn (string $date): string => $dates->formatDate($date), array_slice($this->missingDates, 0, 5));
        $remaining = count($this->missingDates) - count($shown);

        return implode(', ', $shown).($remaining > 0 ? " and {$remaining} more" : '');
    }
}
