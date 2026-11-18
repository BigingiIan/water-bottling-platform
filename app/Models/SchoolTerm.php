<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SchoolTermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolTerm extends Model
{
    /** @use HasFactory<SchoolTermFactory> */
    use HasFactory;

    protected $fillable = [
        'year',
        'term',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'term' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function covers(CarbonInterface $day): bool
    {
        return $day->betweenIncluded($this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay());
    }
}
