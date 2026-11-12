<?php

namespace App\Services\Demo;

use App\Models\Product;
use App\Services\Forecasting\ForecastReadiness;
use App\Services\Inventory\StockService;
use App\Services\Invoicing\InvoiceCalculator;
use App\Services\Revenue\RevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks the invariants the app relies on across the whole database, demo or not.
 */
class DemoDataVerifier
{
    /**
     * Tables counted in the summary.
     *
     * @var list<string>
     */
    public const SUMMARY_TABLES = [
        'users', 'customers', 'orders', 'order_items', 'sales_transactions', 'invoices', 'invoice_items', 'payments',
        'production_schedules', 'production_logs', 'production_batches', 'warehouse_stock', 'stock_movements',
        'stock_counts', 'leakage_reports', 'daily_sku_features', 'forecast_records', 'public_holidays', 'school_terms',
        'promotions', 'equipment', 'maintenance_plans', 'work_orders', 'requests', 'request_comments',
        'notifications', 'audit_log', 'error_events',
    ];

    public function __construct(
        private InvoiceCalculator $calculator,
        private ForecastReadiness $readiness,
        private StockService $stock,
    ) {}

    /**
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    public function checks(): array
    {
        return [
            ...$this->stockLedgerChecks(),
            $this->orderLinesCheck(),
            $this->invoiceTotalsCheck(),
            $this->paymentsCheck(),
            $this->forecastHistoryCheck(),
            $this->stockStatusCheck(),
            $this->agingCheck(),
            $this->pagesCheck(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function tableCounts(): array
    {
        return collect(self::SUMMARY_TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /**
     * Walks every stock line's movements in id order: each level_after must follow from the one before, none
     * may be negative, and the last must equal the line's current level.
     *
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    private function stockLedgerChecks(): array
    {
        $previous = [];
        $negative = 0;
        $broken = 0;

        foreach (DB::table('stock_movements')->orderBy('warehouse_stock_id')->orderBy('id')->select(['warehouse_stock_id', 'quantity_change', 'level_after'])->cursor() as $movement) {
            $line = (int) $movement->warehouse_stock_id;
            $level = (int) $movement->level_after;

            if ($level < 0 || $level - (int) $movement->quantity_change < 0) {
                $negative++;
            }

            if (array_key_exists($line, $previous) && $level !== $previous[$line] + (int) $movement->quantity_change) {
                $broken++;
            }

            $previous[$line] = $level;
        }

        $mismatched = 0;
        $lines = DB::table('warehouse_stock')->get(['id', 'current_level']);

        foreach ($lines as $line) {
            if ((float) $line->current_level < 0) {
                $negative++;
            }

            if (array_key_exists((int) $line->id, $previous) && (float) $line->current_level !== (float) $previous[(int) $line->id]) {
                $mismatched++;
            }
        }

        return [
            ['check' => 'Stock never negative', 'passed' => $negative === 0, 'detail' => $negative === 0 ? "{$lines->count()} lines, every level at or above zero" : "{$negative} negative levels"],
            ['check' => 'Stock equals the ledger', 'passed' => $broken === 0 && $mismatched === 0, 'detail' => $broken === 0 && $mismatched === 0
                ? 'level_after chains on every line and matches current_level'
                : "{$broken} broken links, {$mismatched} lines whose level differs from their last movement"],
        ];
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function orderLinesCheck(): array
    {
        $wrongLines = DB::table('order_items')->whereNotNull('unit_price')->whereRaw('ABS(line_total - quantity * unit_price) > 0.005')->count();
        $orderTotals = DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, SUM(line_total) AS lines_total');
        $wrongInvoices = DB::table('invoices')
            ->joinSub($orderTotals, 'order_totals', 'order_totals.order_id', '=', 'invoices.order_id')
            ->where('invoices.status', '!=', 'void')
            ->whereRaw('ABS(CASE WHEN invoices.prices_include_vat THEN invoices.total ELSE invoices.subtotal END - order_totals.lines_total) > 0.005')
            ->count();
        $passed = $wrongLines === 0 && $wrongInvoices === 0;

        return ['check' => 'Order totals equal their lines', 'passed' => $passed, 'detail' => $passed
            ? DB::table('order_items')->count().' lines priced quantity x price; invoiced orders match their invoice'
            : "{$wrongLines} lines off, {$wrongInvoices} invoices that differ from their order"];
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function invoiceTotalsCheck(): array
    {
        $lineSums = DB::table('invoice_items')->groupBy('invoice_id')->selectRaw('invoice_id, SUM(line_total) AS lines_total')->pluck('lines_total', 'invoice_id');
        $wrongLines = DB::table('invoice_items')->whereRaw('ABS(line_total - quantity * unit_price) > 0.005')->count();
        $wrong = 0;
        $invoices = DB::table('invoices')->where('status', '!=', 'draft')->get(['id', 'vat_rate', 'prices_include_vat', 'subtotal', 'vat_amount', 'total']);

        foreach ($invoices as $invoice) {
            $totals = $this->calculator->totals([bcadd((string) ($lineSums[$invoice->id] ?? '0'), '0', 2)], (string) $invoice->vat_rate, (bool) $invoice->prices_include_vat);

            if (bccomp($totals['subtotal'], (string) $invoice->subtotal, 2) !== 0
                || bccomp($totals['vatAmount'], (string) $invoice->vat_amount, 2) !== 0
                || bccomp($totals['total'], (string) $invoice->total, 2) !== 0) {
                $wrong++;
            }
        }

        $passed = $wrong === 0 && $wrongLines === 0;

        return ['check' => 'Invoice totals equal lines + VAT', 'passed' => $passed, 'detail' => $passed
            ? "{$invoices->count()} issued invoices recomputed with InvoiceCalculator"
            : "{$wrong} invoices with wrong totals, {$wrongLines} lines off"];
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function paymentsCheck(): array
    {
        $paid = DB::table('payments')->whereNull('reversed_at')->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS paid')->pluck('paid', 'invoice_id');
        $problems = 0;
        $invoices = DB::table('invoices')->where('status', '!=', 'draft')->get(['id', 'status', 'total', 'amount_paid']);

        foreach ($invoices as $invoice) {
            $live = bcadd((string) ($paid[$invoice->id] ?? '0'), '0', 2);
            $expectedStatus = match (true) {
                $invoice->status === 'void' => 'void',
                bccomp($live, (string) $invoice->total, 2) >= 0 => 'paid',
                bccomp($live, '0', 2) > 0 => 'partially_paid',
                default => 'issued',
            };

            if (bccomp($live, (string) $invoice->amount_paid, 2) !== 0
                || bccomp($live, (string) $invoice->total, 2) > 0
                || $expectedStatus !== $invoice->status) {
                $problems++;
            }
        }

        return ['check' => 'Payments never exceed totals', 'passed' => $problems === 0, 'detail' => $problems === 0
            ? DB::table('payments')->count().' payments; amount_paid and status agree with them'
            : "{$problems} invoices whose payments, amount paid or status disagree"];
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function forecastHistoryCheck(): array
    {
        $skus = Product::query()->orderBy('unit_type')->pluck('unit_type')->all();
        $readiness = $this->readiness->forSkus($skus, CarbonImmutable::today()->addDay());
        $required = $this->readiness->requiredDays();
        $covered = DB::table('daily_sku_features')->where('sales_data_covered', true)->groupBy('product_sku')->selectRaw('product_sku, COUNT(*) AS days')->pluck('days', 'product_sku');
        $short = array_filter($skus, fn (string $sku): bool => $readiness[$sku]['coveredDays'] < $required);
        $detail = collect($skus)->map(fn (string $sku): string => "{$sku} ".(int) ($covered[$sku] ?? 0))->implode(', ');

        return ['check' => "{$required}+ covered days per SKU", 'passed' => $skus !== [] && $short === [], 'detail' => $short === []
            ? "covered days: {$detail}"
            : 'short of '.$required.' days before tomorrow: '.implode(', ', $short)];
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function stockStatusCheck(): array
    {
        $statuses = Product::query()->orderBy('unit_type')->get()
            ->mapWithKeys(fn (Product $product): array => [$product->unit_type => $this->stock->statusForProduct($product)]);
        $missing = array_diff(['ok', 'low', 'critical'], $statuses->unique()->all());

        return ['check' => 'Every stock status shows', 'passed' => $missing === [], 'detail' => $statuses->map(fn (string $status, string $sku): string => "{$sku} {$status}")->implode(', ')
            .($missing === [] ? '' : ' (missing: '.implode(', ', $missing).')')];
    }

    /**
     * Overdue invoices in every aging bucket the data is old enough to reach.
     *
     * @return array{check: string, passed: bool, detail: string}
     */
    private function agingCheck(): array
    {
        $today = CarbonImmutable::today();
        $overdue = DB::table('invoices')
            ->whereIn('status', RevenueReport::OPEN_STATUSES)
            ->whereDate('due_date', '<', $today->toDateString())
            ->pluck('issue_date')
            ->map(fn (string $date): int => (int) CarbonImmutable::parse($date)->diffInDays($today, true));
        $oldest = DB::table('invoices')->min('issue_date');
        $reach = $oldest === null ? 0 : (int) CarbonImmutable::parse($oldest)->diffInDays($today, true);
        $buckets = [
            '0-30' => [0, 30],
            '31-60' => [31, 60],
            '61-90' => [61, 90],
            '90+' => [91, PHP_INT_MAX],
        ];
        $counts = [];
        $empty = [];

        foreach ($buckets as $label => [$from, $to]) {
            $counts[$label] = $overdue->filter(fn (int $age): bool => $age >= $from && $age <= $to)->count();

            // A bucket can only hold overdue invoices once the data goes back past its start plus the terms.
            if ($counts[$label] === 0 && $reach >= $from + 30) {
                $empty[] = $label;
            }
        }

        $total = DB::table('invoices')->whereIn('status', RevenueReport::BILLED_STATUSES)->count();
        $detail = $overdue->count().' of '.$total.' invoices overdue ('
            .collect($counts)->map(fn (int $count, string $label): string => "{$label} days: {$count}")->implode(', ').')';

        return ['check' => 'Overdue invoices across aging buckets', 'passed' => $overdue->isNotEmpty() && $empty === [], 'detail' => $detail.($empty === [] ? '' : ' (empty: '.implode(', ', $empty).')')];
    }

    /**
     * Each page's main query has rows to show.
     *
     * @return array{check: string, passed: bool, detail: string}
     */
    private function pagesCheck(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $pages = [
            'Dashboard (runs today)' => DB::table('production_schedules')->whereDate('scheduled_date', $today)->count(),
            'Production schedule' => DB::table('production_schedules')->whereDate('scheduled_date', '>=', $today)->count(),
            'Production runs' => DB::table('production_batches')->where('status', 'completed')->count(),
            'Inventory' => DB::table('warehouse_stock')->where('current_level', '>', 0)->count(),
            'Stock movements' => DB::table('stock_movements')->count(),
            'Stock counts' => DB::table('stock_counts')->count(),
            'Leakage' => DB::table('leakage_reports')->count(),
            'Orders' => DB::table('orders')->count(),
            'Invoices' => DB::table('invoices')->whereIn('status', RevenueReport::BILLED_STATUSES)->count(),
            'Revenue (last 30 days)' => DB::table('payments')->whereNull('reversed_at')->where('paid_at', '>=', CarbonImmutable::today()->subDays(30))->count(),
            'Forecasting' => DB::table('daily_sku_features')->where('sales_data_covered', true)->count(),
            'Business Calendar' => DB::table('promotions')->count(),
            'Equipment' => DB::table('equipment')->count(),
            'Work orders' => DB::table('work_orders')->count(),
            'Maintenance calendar' => DB::table('maintenance_plans')->where('active', true)->count(),
            'Requests' => DB::table('requests')->count(),
            'Notifications' => DB::table('notifications')->count(),
            'Staff directory' => DB::table('users')->count(),
            'Error logs' => DB::table('error_events')->count(),
        ];
        $empty = array_keys(array_filter($pages, fn (int $count): bool => $count === 0));

        return ['check' => "Every page's main query returns rows", 'passed' => $empty === [], 'detail' => $empty === []
            ? count($pages).' pages checked'
            : 'empty: '.implode(', ', $empty)];
    }
}
