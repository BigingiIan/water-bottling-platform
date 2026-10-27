<?php

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LeakageReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionSchedule;
use App\Services\Forecasting\ForecastOverview;
use App\Services\Inventory\StockService;
use App\Services\Invoicing\InvoiceStatus;
use App\Services\Orders\Money;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures, each computed with a fixed number of queries and memoised for the request so the
 * widgets and the attention list can share them.
 */
class DashboardMetrics
{
    /**
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * @return array{target: int, actual: int, percent: int, scheduledRuns: int, openRuns: Collection<int, ProductionSchedule>}
     */
    public function productionToday(): array
    {
        return $this->memo[__FUNCTION__] ??= (function (): array {
            $schedules = ProductionSchedule::query()
                ->with('productionLine:id,name')
                ->withSum('logs', 'units_logged')
                ->whereDate('scheduled_date', today()->toDateString())
                ->orderBy('scheduled_time')
                ->get();
            $target = (int) $schedules->sum('target_units');
            $actual = (int) $schedules->sum('logs_sum_units_logged');

            return [
                'target' => $target,
                'actual' => $actual,
                'percent' => $target > 0 ? (int) round($actual / $target * 100) : 0,
                'scheduledRuns' => $schedules->count(),
                'openRuns' => $schedules->whereIn('status', ['active', 'queued'])->values(),
            ];
        })();
    }

    /**
     * Products whose combined stock is low, critical or out, worst first.
     *
     * @return Collection<int, array{product: Product, status: string, units: float}>
     */
    public function stockAlerts(): Collection
    {
        return $this->memo[__FUNCTION__] ??= (function (): Collection {
            $stockService = app(StockService::class);
            $totals = $this->stockOnHand();

            return Product::query()
                ->orderBy('unit_type')
                ->get(['id', 'name', 'unit_type', 'reorder_threshold'])
                ->map(fn (Product $product): array => [
                    'product' => $product,
                    'units' => $units = (float) ($totals[$product->id] ?? 0),
                    'status' => $stockService->statusForProduct($product, $units),
                ])
                ->filter(fn (array $alert): bool => $alert['status'] !== 'ok')
                ->sortBy(fn (array $alert): int => match ($alert['status']) {
                    'out' => 0,
                    'critical' => 1,
                    default => 2,
                })
                ->values();
        })();
    }

    /**
     * Open leakage reports graded review or investigate, from any source.
     */
    public function leakageAttentionCount(): int
    {
        return $this->memo[__FUNCTION__] ??= LeakageReport::query()->where('status', 'open')->whereNotNull('severity')->count();
    }

    /**
     * The oldest open reports graded investigate.
     *
     * @return Collection<int, LeakageReport>
     */
    public function leakageToInvestigate(int $limit): Collection
    {
        return $this->memo[__FUNCTION__.$limit] ??= LeakageReport::query()
            ->with('product:id,unit_type')
            ->where('status', 'open')
            ->where('severity', 'investigate')
            ->oldest('report_date')
            ->oldest('id')
            ->limit($limit)
            ->get(['id', 'product_id', 'report_date', 'loss_percent', 'created_at']);
    }

    /**
     * @return array{enabled: bool, rows: Collection<int, array{product: Product, forecast: ?float, units: float, coverDays: ?float}>}
     */
    public function forecastCover(): array
    {
        return $this->memo[__FUNCTION__] ??= app(SettingsService::class)->get('forecast.enabled', true)
            ? ['enabled' => true, 'rows' => app(ForecastOverview::class)->tomorrowCover()]
            : ['enabled' => false, 'rows' => collect()];
    }

    /**
     * Confirmed orders due by today, and dispatched orders not yet delivered.
     *
     * @return array{toDispatch: int, awaitingDelivery: int, dueToDispatch: Collection<int, Order>}
     */
    public function ordersToday(): array
    {
        return $this->memo[__FUNCTION__] ??= (function (): array {
            $today = today()->toDateString();
            $counts = Order::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->where(fn ($query) => $query
                    ->where('status', OrderStatus::Dispatched->value)
                    ->orWhere(fn ($query) => $query
                        ->where('status', OrderStatus::Confirmed->value)
                        ->whereDate(DB::raw('COALESCE(requested_date, order_date)'), '<=', $today)))
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $dueToDispatch = Order::query()
                ->with('customer:id,name')
                ->where('status', OrderStatus::Confirmed->value)
                ->whereDate(DB::raw('COALESCE(requested_date, order_date)'), '<=', $today)
                ->orderByRaw('COALESCE(requested_date, order_date)')
                ->orderBy('id')
                ->limit(3)
                ->get(['id', 'order_number', 'customer_id', 'order_date', 'requested_date']);

            return [
                'toDispatch' => (int) ($counts[OrderStatus::Confirmed->value] ?? 0),
                'awaitingDelivery' => (int) ($counts[OrderStatus::Dispatched->value] ?? 0),
                'dueToDispatch' => $dueToDispatch,
            ];
        })();
    }

    /**
     * Confirmed orders that cannot be dispatched from stock on hand, allocating stock to the oldest
     * requested orders first, as dispatch does.
     *
     * @return Collection<int, array{order: Order, shortfalls: list<string>}>
     */
    public function undispatchableOrders(): Collection
    {
        return $this->memo[__FUNCTION__] ??= (function (): Collection {
            $orders = Order::query()
                ->with(['items:id,order_id,product_id,quantity', 'items.product:id,unit_type'])
                ->where('status', OrderStatus::Confirmed->value)
                ->orderByRaw('COALESCE(requested_date, order_date)')
                ->orderBy('id')
                ->get(['id', 'order_number', 'order_date', 'requested_date']);
            $remaining = $orders->isEmpty() ? [] : array_map('intval', $this->stockOnHand()->all());
            $blocked = collect();

            foreach ($orders as $order) {
                $shortfalls = [];

                foreach ($order->items as $item) {
                    $available = $remaining[$item->product_id] ?? 0;

                    if ($item->quantity > $available) {
                        $shortfalls[] = number_format($item->quantity - $available).' × '.($item->product?->unit_type ?? 'product');
                    }

                    $remaining[$item->product_id] = max(0, $available - $item->quantity);
                }

                if ($shortfalls !== []) {
                    $blocked->push(['order' => $order, 'shortfalls' => $shortfalls]);
                }
            }

            return $blocked;
        })();
    }

    /**
     * Money still owed on issued invoices, and how much of it is overdue.
     *
     * @return array{outstanding: string, overdue: string, openCount: int, overdueCount: int}
     */
    public function receivables(): array
    {
        return $this->memo[__FUNCTION__] ??= (function (): array {
            $today = today()->toDateString();
            $row = Invoice::query()
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                ->selectRaw('COUNT(*) as open_count, COALESCE(SUM(total - amount_paid), 0) as outstanding')
                ->selectRaw('SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) as overdue_count', [$today])
                ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN total - amount_paid ELSE 0 END), 0) as overdue', [$today])
                ->toBase()
                ->first();

            return [
                'outstanding' => Money::fromAggregate($row->outstanding ?? null),
                'overdue' => Money::fromAggregate($row->overdue ?? null),
                'openCount' => (int) ($row->open_count ?? 0),
                'overdueCount' => (int) ($row->overdue_count ?? 0),
            ];
        })();
    }

    /**
     * The longest-overdue invoices.
     *
     * @return Collection<int, Invoice>
     */
    public function overdueInvoices(int $limit): Collection
    {
        return $this->memo[__FUNCTION__.$limit] ??= Invoice::query()
            ->overdue()
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function recentActivity(int $limit): Collection
    {
        return $this->memo[__FUNCTION__.$limit] ??= AuditLog::query()
            ->with('user:id,name')
            ->latest('timestamp')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int|string, mixed>
     */
    private function stockOnHand(): Collection
    {
        return $this->memo[__FUNCTION__] ??= DB::table('warehouse_stock')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(current_level) as total_units')
            ->pluck('total_units', 'product_id');
    }
}
