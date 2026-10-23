<?php

namespace App\Services\Leakage;

final readonly class AnomalyAssessment
{
    public const MINIMUM_BATCHES = 10;

    public const BASELINE_BATCHES = 30;

    public const THRESHOLD = 3.5;

    /**
     * @param  'unusual'|'normal'|'skipped'  $status
     * @param  'product and line'|'product'|null  $scope
     */
    public function __construct(
        public string $status,
        public ?float $zScore,
        public int $baselineSize,
        public ?string $scope,
        public ?float $median = null,
        public ?float $mad = null,
        public ?string $reason = null,
    ) {}

    public function isUnusual(): bool
    {
        return $this->status === 'unusual';
    }

    public function sentence(): string
    {
        return match ($this->status) {
            'skipped' => "Anomaly check skipped: {$this->reason}.",
            'unusual' => sprintf(
                'Unusual for this %s: robust z = %.1f (median %.1f%%, MAD %.2f over %d batches).',
                $this->scope, $this->zScore, $this->median, $this->mad, $this->baselineSize,
            ),
            default => sprintf('Within the usual range for this %s (robust z = %.1f over %d batches).', $this->scope, $this->zScore, $this->baselineSize),
        };
    }
}
