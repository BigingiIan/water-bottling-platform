<?php

namespace App\Services\Leakage;

/**
 * Stage A for one batch. Volumes are litres as decimal strings; percentages as decimal strings (3 dp).
 */
final readonly class ProcessLossAssessment
{
    public function __construct(
        public string $batchReference,
        public string $sku,
        public ?string $lineName,
        public string $rawLitres,
        public string $normalLossPercent,
        public string $expectedLitres,
        public string $loggedLitres,
        public int $loggedUnits,
        public string $lossLitres,
        public ?string $totalLossPercent,
        public ?string $lossPercent,
        public ?string $lossSeverity,
        public AnomalyAssessment $anomaly,
        public string $meteredLitres,
        public ?string $gapPercent,
        public ?string $gapSeverity,
    ) {}

    public function reportsLoss(): bool
    {
        return $this->lossSeverity !== null || $this->anomaly->isUnusual();
    }

    public function lossDetectedBy(): string
    {
        return $this->lossSeverity !== null ? 'rule' : 'anomaly';
    }

    public function lossExplanation(): string
    {
        $subject = "Batch {$this->batchReference} ({$this->sku}".($this->lineName ? ", {$this->lineName}" : '').')';

        return sprintf(
            '%s lost %s%% of its intake against an expected %s%% normal loss (%s%% beyond normal): %s L intake, %s L expected after normal loss, %s L logged as %s. %s',
            $subject,
            self::percent($this->totalLossPercent),
            self::percent($this->normalLossPercent),
            self::percent($this->lossPercent),
            self::litres($this->rawLitres),
            self::litres($this->expectedLitres),
            self::litres($this->loggedLitres),
            number_format($this->loggedUnits).' bottles',
            $this->anomaly->sentence(),
        );
    }

    public function gapExplanation(): string
    {
        $metered = (float) $this->meteredLitres;
        $logged = (float) $this->loggedLitres;
        $cause = $metered > $logged
            ? 'bottles were filled but not logged, or the bottled volume was over-recorded'
            : 'more bottles were logged than the meter recorded, so the bottled volume looks under-recorded';

        return sprintf(
            'Batch %s (%s) metered %s L bottled but %s L were logged as bottles (%s%% gap): %s.',
            $this->batchReference,
            $this->sku,
            self::litres($this->meteredLitres),
            self::litres($this->loggedLitres),
            self::percent($this->gapPercent),
            $cause,
        );
    }

    public static function percent(?string $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1);
    }

    public static function litres(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    }
}
