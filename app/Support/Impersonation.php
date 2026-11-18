<?php

namespace App\Support;

use App\Exceptions\ViewAsReadOnlyException;
use Closure;

final class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    private static int $writeAllowanceDepth = 0;

    /**
     * Whether the current session is an administrator using View As.
     */
    public static function active(): bool
    {
        if (! app()->bound('session')) {
            return false;
        }

        return app('session.store')->has(self::SESSION_KEY);
    }

    public static function impersonatorId(): mixed
    {
        return self::active() ? app('session.store')->get(self::SESSION_KEY) : null;
    }

    public static function blocksWrites(): bool
    {
        return self::$writeAllowanceDepth === 0 && self::active();
    }

    /**
     * Run a callback that is allowed to write while View As is active (audit trail, error log).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function allowingWrites(Closure $callback): mixed
    {
        self::$writeAllowanceDepth++;

        try {
            return $callback();
        } finally {
            self::$writeAllowanceDepth--;
        }
    }

    public static function ensureWritable(): void
    {
        if (self::blocksWrites()) {
            self::deny();
        }
    }

    public static function deny(): never
    {
        throw new ViewAsReadOnlyException;
    }

    /**
     * Refuse a SQL statement that would change data outside the tables View As needs to keep working.
     */
    public static function guardQuery(string $sql): void
    {
        if (! self::blocksWrites()) {
            return;
        }

        $table = self::writeTarget($sql);

        if ($table === false) {
            return;
        }

        if ($table !== null && in_array($table, self::allowedTables(), true)) {
            return;
        }

        self::deny();
    }

    /**
     * Returns false for read statements, the target table for recognised writes, and null for writes with no
     * recognisable table (such as a CTE that modifies data), which are always refused.
     */
    public static function writeTarget(string $sql): string|false|null
    {
        $statement = ltrim($sql);
        $identifier = '((?:[`"\[]?[\w$]+[`"\]]?\.)*[`"\[]?([\w$]+)[`"\]]?)';
        $patterns = [
            '/^insert\s+(?:or\s+\w+\s+|ignore\s+)?into\s+'.$identifier.'/i',
            '/^replace\s+into\s+'.$identifier.'/i',
            '/^update\s+(?:or\s+\w+\s+|ignore\s+)?'.$identifier.'/i',
            '/^delete\s+from\s+'.$identifier.'/i',
            '/^truncate\s+(?:table\s+)?'.$identifier.'/i',
            '/^(?:alter|drop|create)\s+(?:temporary\s+)?table\s+(?:if\s+(?:not\s+)?exists\s+)?'.$identifier.'/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $statement, $matches) === 1) {
                return strtolower($matches[2]);
            }
        }

        if (preg_match('/^(?:alter|drop|create)\s/i', $statement) === 1) {
            return null;
        }

        if (preg_match('/^with\s/i', $statement) === 1 && preg_match('/\)\s*(?:insert|update|delete|replace)\b/i', $statement) === 1) {
            return null;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function allowedTables(): array
    {
        $tables = [config('session.table', 'sessions'), 'cache', 'cache_locks'];

        foreach ((array) config('cache.stores', []) as $store) {
            foreach (['table', 'lock_table'] as $key) {
                $tables[] = $store[$key] ?? null;
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $table): string => strtolower($table),
            array_filter($tables, static fn (mixed $table): bool => is_string($table) && $table !== ''),
        )));
    }
}
