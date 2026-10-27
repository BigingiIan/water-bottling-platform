<?php

namespace App\Services\Revenue;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting window and the like-for-like window it is compared against. Both ends are inclusive dates.
 */
final readonly class RevenuePeriod
{
    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'custom' => 'Custom range',
    ];

    public const MAX_DAYS = 366;

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public CarbonImmutable $today,
    ) {}

    /**
     * @throws InvalidArgumentException when a custom range is missing, reversed or longer than MAX_DAYS
     */
    public static function fromInput(string $preset, ?string $from, ?string $to, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();

        return match ($preset) {
            'last_month' => self::lastMonth($today),
            'this_quarter' => self::toDate($preset, $today->startOfQuarter(), $today, $today->startOfQuarter()->subQuarterNoOverflow(), $today),
            'custom' => self::custom($from, $to, $today),
            default => self::toDate('this_month', $today->startOfMonth(), $today, $today->startOfMonth()->subMonthNoOverflow(), $today),
        };
    }

    /**
     * The date outstanding and overdue balances are measured at: the period end, or today if that is sooner.
     */
    public function asAt(): CarbonImmutable
    {
        return $this->to->greaterThan($this->today) ? $this->today : $this->to;
    }

    public function previousAsAt(): CarbonImmutable
    {
        return $this->previousTo->greaterThan($this->today) ? $this->today : $this->previousTo;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * A period that runs from the start of a month or quarter up to today, compared with the same number of
     * days from the start of the previous month or quarter (clipped to its end).
     */
    private static function toDate(string $preset, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom, CarbonImmutable $today): self
    {
        $previousEnd = $preset === 'this_quarter' ? $previousFrom->endOfQuarter()->startOfDay() : $previousFrom->endOfMonth()->startOfDay();
        $previousTo = $previousFrom->addDays((int) $from->diffInDays($to));

        return new self($preset, $from, $to, $previousFrom, $previousTo->greaterThan($previousEnd) ? $previousEnd : $previousTo, $today);
    }

    private static function lastMonth(CarbonImmutable $today): self
    {
        $from = $today->startOfMonth()->subMonthNoOverflow();
        $previousFrom = $from->subMonthNoOverflow();

        return new self('last_month', $from, $from->endOfMonth()->startOfDay(), $previousFrom, $previousFrom->endOfMonth()->startOfDay(), $today);
    }

    private static function custom(?string $from, ?string $to, CarbonImmutable $today): self
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Choose both a start and an end date.');
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Choose a range of '.self::MAX_DAYS.' days or fewer.');
        }

        return new self('custom', $start, $end, $start->subDays($days), $start->subDay(), $today);
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
