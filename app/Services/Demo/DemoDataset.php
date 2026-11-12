<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Demo rows held in memory until flush(). Every row gets an explicit id up front, so related rows can
 * point at each other without a database round trip, and each table's demo rows form one id range.
 */
class DemoDataset
{
    /**
     * Parents before children, so foreign keys hold at every insert.
     *
     * @var list<string>
     */
    public const TABLE_ORDER = [
        'users',
        'user_preferences',
        'customers',
        'school_terms',
        'promotions',
        'production_schedules',
        'production_logs',
        'production_batches',
        'warehouse_stock',
        'orders',
        'order_items',
        'stock_movements',
        'sales_transactions',
        'stock_counts',
        'leakage_reports',
        'invoices',
        'invoice_items',
        'payments',
        'equipment',
        'maintenance_plans',
        'requests',
        'request_comments',
        'work_orders',
        'notifications',
        'error_events',
        'audit_log',
    ];

    /**
     * Tables whose primary key is not an auto-increment id; their rows are removed through the demo users.
     *
     * @var list<string>
     */
    private const UNTRACKED_TABLES = ['notifications'];

    private const CHUNK_SIZE = 500;

    /**
     * @var array<string, array<int|string, array<string, mixed>>>
     */
    private array $rows = [];

    /**
     * @var array<string, int>
     */
    private array $nextIds = [];

    /**
     * @var array<string, int>
     */
    private array $inserted = [];

    /**
     * Queues a row and returns the id it will be inserted with.
     *
     * @param  array<string, mixed>  $row
     */
    public function add(string $table, array $row): int
    {
        $this->nextIds[$table] ??= (int) DB::table($table)->max('id') + 1;
        $id = $this->nextIds[$table]++;
        $this->rows[$table][$id] = ['id' => $id, ...$row];

        return $id;
    }

    /**
     * Queues a row that brings its own key (such as a notification UUID).
     *
     * @param  array<string, mixed>  $row
     */
    public function addKeyed(string $table, array $row): void
    {
        $this->rows[$table][] = $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(string $table, int $id, array $values): void
    {
        $this->rows[$table][$id] = [...$this->rows[$table][$id], ...$values];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $table, int $id): array
    {
        return $this->rows[$table][$id];
    }

    /**
     * Ids that the next add() to the table will hand out, without queueing anything.
     */
    public function peekNextId(string $table): int
    {
        return $this->nextIds[$table] ??= (int) DB::table($table)->max('id') + 1;
    }

    /**
     * Inserts everything queued, records the id ranges, and empties the buffers.
     */
    public function flush(): void
    {
        $tables = [...self::TABLE_ORDER, ...array_diff(array_keys($this->rows), self::TABLE_ORDER)];

        foreach ($tables as $table) {
            $rows = array_values($this->rows[$table] ?? []);

            if ($rows === []) {
                continue;
            }

            // Bulk inserts need the same columns on every row.
            $columns = array_fill_keys(array_unique(array_merge(...array_map('array_keys', $rows))), null);

            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                DB::table($table)->insert(array_map(fn (array $row): array => array_replace($columns, $row), $chunk));
            }

            $this->inserted[$table] = ($this->inserted[$table] ?? 0) + count($rows);

            if (! in_array($table, self::UNTRACKED_TABLES, true)) {
                $ids = array_column($rows, 'id');
                $this->recordRange($table, min($ids), max($ids));
            }
        }

        $this->rows = [];
    }

    /**
     * Rows inserted per table by this dataset so far.
     *
     * @return array<string, int>
     */
    public function insertedCounts(): array
    {
        return $this->inserted;
    }

    public static function recordDates(string $table, string $firstDate, string $lastDate): void
    {
        DB::table('demo_data_ranges')->upsert(
            [['table_name' => $table, 'first_date' => $firstDate, 'last_date' => $lastDate, 'created_at' => now()]],
            ['table_name'],
            ['first_date', 'last_date'],
        );
    }

    /**
     * Records rows written outside the dataset (such as forecasts) as demo rows.
     */
    public static function recordIds(string $table, int $firstId, int $lastId): void
    {
        (new self)->recordRange($table, $firstId, $lastId);
    }

    private function recordRange(string $table, int $firstId, int $lastId): void
    {
        $existing = DB::table('demo_data_ranges')->where('table_name', $table)->first();

        DB::table('demo_data_ranges')->upsert(
            [[
                'table_name' => $table,
                'first_id' => min($firstId, (int) ($existing->first_id ?? $firstId)),
                'last_id' => max($lastId, (int) ($existing->last_id ?? $lastId)),
                'created_at' => now(),
            ]],
            ['table_name'],
            ['first_id', 'last_id'],
        );
    }
}
