<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes what demo:seed wrote: each table's recorded id range, plus rows added later that point straight
 * at a demo row (a payment recorded against a demo invoice, a comment on a demo request).
 */
class DemoDataCleaner
{
    /**
     * Children before parents, with the columns that point at another demo table.
     *
     * @var array<string, array<string, string>>
     */
    private const DELETE_ORDER = [
        'user_preferences' => ['user_id' => 'users'],
        'request_comments' => ['request_id' => 'requests', 'user_id' => 'users'],
        'work_orders' => ['equipment_id' => 'equipment', 'request_id' => 'requests'],
        'maintenance_plans' => ['equipment_id' => 'equipment'],
        'requests' => ['from_user_id' => 'users'],
        'payments' => ['invoice_id' => 'invoices'],
        'invoice_items' => ['invoice_id' => 'invoices'],
        'invoices' => ['order_id' => 'orders', 'customer_id' => 'customers'],
        'sales_transactions' => ['customer_id' => 'customers', 'stock_id' => 'warehouse_stock'],
        'leakage_reports' => ['batch_id' => 'production_batches', 'stock_count_id' => 'stock_counts'],
        'stock_movements' => ['warehouse_stock_id' => 'warehouse_stock'],
        'stock_counts' => [],
        'order_items' => ['order_id' => 'orders'],
        'orders' => ['customer_id' => 'customers'],
        'warehouse_stock' => ['batch_id' => 'production_batches'],
        'production_batches' => ['production_schedule_id' => 'production_schedules'],
        'production_logs' => ['production_schedule_id' => 'production_schedules', 'operator_id' => 'users'],
        'production_schedules' => [],
        'equipment' => [],
        'promotions' => [],
        'school_terms' => [],
        'customers' => [],
        'forecast_records' => [],
        'error_events' => [],
        'audit_log' => [],
        'users' => [],
    ];

    public function hasDemoData(): bool
    {
        return DB::table('demo_data_ranges')->exists();
    }

    /**
     * @return array<string, int> rows removed per table
     */
    public function clear(): array
    {
        $ranges = $this->ranges();

        if ($ranges->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($ranges): array {
            $removed = [];

            if ($ranges->has('users')) {
                $removed['notifications'] = DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->whereBetween('notifiable_id', $this->between($ranges['users']))
                    ->delete();
            }

            if ($ranges->has('daily_sku_features')) {
                // The sales behind these days are gone, so they no longer count as forecasting history.
                $removed['daily_sku_features (uncovered)'] = DB::table('daily_sku_features')
                    ->whereBetween('date', [$ranges['daily_sku_features']->first_date, $ranges['daily_sku_features']->last_date])
                    ->where('sales_data_covered', true)
                    ->update(['sales_data_covered' => false, 'updated_at' => now()]);
            }

            foreach (self::DELETE_ORDER as $table => $parents) {
                $parents = array_filter($parents, fn (string $parent): bool => $ranges->has($parent) && $ranges[$parent]->first_id !== null);

                if (! $ranges->has($table) && $parents === []) {
                    continue;
                }

                $removed[$table] = DB::table($table)
                    ->where(function (Builder $query) use ($table, $parents, $ranges): void {
                        if ($ranges->has($table) && $ranges[$table]->first_id !== null) {
                            $query->orWhereBetween('id', $this->between($ranges[$table]));
                        }

                        foreach ($parents as $column => $parent) {
                            $query->orWhereBetween($column, $this->between($ranges[$parent]));
                        }
                    })
                    ->delete();
            }

            DB::table('demo_data_ranges')->delete();

            return array_filter($removed);
        });
    }

    /**
     * @return Collection<string, object>
     */
    private function ranges(): Collection
    {
        return DB::table('demo_data_ranges')->get()->keyBy('table_name');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function between(object $range): array
    {
        return [(int) $range->first_id, (int) $range->last_id];
    }
}
