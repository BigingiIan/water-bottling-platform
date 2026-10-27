<?php

namespace App\Services\Leakage;

/**
 * Stage B for one count of a product at a location. All quantities are bottles (units).
 */
final readonly class ShrinkageAssessment
{
    public function __construct(
        public string $sku,
        public ?string $location,
        public int $openingUnits,
        public bool $openingFromCount,
        public int $productionIn,
        public int $salesOut,
        public int $adjustmentsNet,
        public int $expectedUnits,
        public int $systemUnits,
        public int $countedUnits,
        public int $shrinkageUnits,
        public ?string $shrinkagePercent,
        public ?string $severity,
    ) {}

    public function explanation(): string
    {
        $place = $this->location ? " at {$this->location}" : '';
        $sentence = sprintf(
            '%s%s: counted %s but expected %s (%s %s + %s produced − %s sold%s), so %s units (%s%%) are missing.',
            $this->sku,
            $place,
            number_format($this->countedUnits),
            number_format($this->expectedUnits),
            $this->openingFromCount ? 'last count' : 'opening level',
            number_format($this->openingUnits),
            number_format($this->productionIn),
            number_format($this->salesOut),
            $this->adjustmentsNet === 0 ? '' : ' '.($this->adjustmentsNet < 0 ? '−' : '+').' '.number_format(abs($this->adjustmentsNet)).' adjusted',
            number_format($this->shrinkageUnits),
            ProcessLossAssessment::percent($this->shrinkagePercent),
        );

        if ($this->systemUnits !== $this->expectedUnits) {
            $sentence .= sprintf(
                ' The system level was %s, so %s units changed outside recorded movements.',
                number_format($this->systemUnits),
                number_format($this->systemUnits - $this->expectedUnits),
            );
        }

        return $sentence;
    }
}
