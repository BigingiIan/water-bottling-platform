<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Kenya's fixed-date public holidays. Movable holidays (Good Friday, Easter Monday, Eid al-Fitr and
     * others) are entered each year once announced, so none are guessed here. Names can be edited in the
     * Business Calendar.
     *
     * @var list<array{name: string, month_day: string}>
     */
    private const FIXED_HOLIDAYS = [
        ['name' => "New Year's Day", 'month_day' => '01-01'],
        ['name' => 'Labour Day', 'month_day' => '05-01'],
        ['name' => 'Madaraka Day', 'month_day' => '06-01'],
        ['name' => 'Mazingira Day', 'month_day' => '10-10'],
        ['name' => 'Mashujaa Day', 'month_day' => '10-20'],
        ['name' => 'Jamhuri Day', 'month_day' => '12-12'],
        ['name' => 'Christmas Day', 'month_day' => '12-25'],
        ['name' => 'Boxing Day', 'month_day' => '12-26'],
    ];

    /**
     * Run the database seeds. Safe to rerun: a recurring holiday on the same month and day is left as is.
     */
    public function run(): void
    {
        $existing = PublicHoliday::query()
            ->where('recurring', true)
            ->get(['date'])
            ->map(fn (PublicHoliday $holiday): string => $holiday->date->format('m-d'))
            ->all();

        foreach (self::FIXED_HOLIDAYS as $holiday) {
            if (in_array($holiday['month_day'], $existing, true)) {
                continue;
            }

            PublicHoliday::query()->create([
                'name' => $holiday['name'],
                // The year is only a placeholder: recurring holidays match the month and day in every year.
                'date' => PublicHoliday::RECURRING_YEAR.'-'.$holiday['month_day'],
                'recurring' => true,
            ]);
        }
    }
}
