<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /**
     * Placeholder year stored for recurring holidays. A leap year, so 29 February can be recorded.
     */
    public const RECURRING_YEAR = 2000;

    protected $fillable = [
        'name',
        'date',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
        ];
    }

    public function fallsOn(CarbonInterface $day): bool
    {
        return $this->recurring
            ? $this->date->format('m-d') === $day->format('m-d')
            : $this->date->isSameDay($day);
    }

    /**
     * "1 May every year" for recurring holidays, the full date otherwise.
     */
    public function describeDate(string $dateFormat): string
    {
        return $this->recurring
            ? $this->date->format('j F').' every year'
            : $this->date->format($dateFormat);
    }
}
