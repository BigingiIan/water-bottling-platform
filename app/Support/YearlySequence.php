<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free per-year document numbers (ORD-2026-0001, INV-2026-0001) from a row-locked counter table.
 */
class YearlySequence
{
    /**
     * Must run inside the transaction that stores the numbered document, so the locked counter row is
     * released only when that document is committed (or rolled back with it).
     */
    public function next(string $table, string $prefix, int $year): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("{$prefix} numbers must be generated inside a database transaction.");
        }

        DB::table($table)->insertOrIgnore(['year' => $year, 'last_number' => 0]);

        // The increment is a single atomic UPDATE, so two writers can never read the same value; the row
        // lock it takes is held until the surrounding transaction ends.
        DB::table($table)->where('year', $year)->increment('last_number');
        $number = (int) DB::table($table)->where('year', $year)->lockForUpdate()->value('last_number');

        return sprintf('%s-%d-%04d', $prefix, $year, $number);
    }
}
