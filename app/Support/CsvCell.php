<?php

namespace App\Support;

/**
 * Neutralises spreadsheet formula injection in CSV exports.
 *
 * Excel and other spreadsheets execute a text cell that starts with =, +, -, @, tab or carriage return, so a
 * customer named "=HYPERLINK(...)" would run when the export is opened. Such text is prefixed with a single
 * quote, which makes the spreadsheet show it as plain text. Genuine numbers (including negative amounts) are
 * left alone so they still sum.
 */
final class CsvCell
{
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * @param  array<int|string, mixed>  $cells
     * @return array<int|string, mixed>
     */
    public static function escapeRow(array $cells): array
    {
        return array_map(self::escape(...), $cells);
    }
}
