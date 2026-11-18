<?php

namespace App\Services\Revenue;

use App\Models\ForecastRecord;
use App\Models\Product;
use App\Services\Orders\Money;
use App\Support\SettingsDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenue figures aggregated in SQL. Every method runs a fixed number of queries whatever the data size.
 *
 * Billed invoices are issued, partially paid or paid (drafts and voids never count); collected money is
 * payments that have not been reversed. Days are app-timezone dates, like issue_date and due_date.
 */
class RevenueReport
{
    public const BILLED_STATUSES = ['issued', 'partially_paid', 'paid'];

    public const OPEN_STATUSES = ['issued', 'partially_paid'];

    public const AGING_BUCKETS = [
        'current' => '0–30 days',
        'days31to60' => '31–60 days',
        'days61to90' => '61–90 days',
        'over90' => '90+ days',
    ];

    public const FORECAST_DAYS = 7;

    public function __construct(private SettingsDate $dates) {}

    /**
     * @return array<string, array{current: string, previous: string, change: ?string}>
     */
    public function kpis(RevenuePeriod $period): array
    {
        [$from, $until] = $this->bounds($period->from, $period->to);
        [$previousFrom, $previousUntil] = $this->bounds($period->previousFrom, $period->previousTo);

        $invoiced = DB::table('invoices')
            ->whereIn('status', self::BILLED_STATUSES)
            ->where('issue_date', '>=', $previousFrom)
            ->where('issue_date', '<', $until)
            ->selectRaw(
                'SUM(CASE WHEN issue_date >= ? AND issue_date < ? THEN subtotal ELSE 0 END) AS current_net,'
                .' SUM(CASE WHEN issue_date >= ? AND issue_date < ? THEN total ELSE 0 END) AS current_gross,'
                .' SUM(CASE WHEN issue_date >= ? AND issue_date < ? THEN subtotal ELSE 0 END) AS previous_net,'
                .' SUM(CASE WHEN issue_date >= ? AND issue_date < ? THEN total ELSE 0 END) AS previous_gross',
                [$from, $until, $from, $until, $previousFrom, $previousUntil, $previousFrom, $previousUntil],
            )
            ->first();

        $collected = DB::table('payments')
            ->whereNull('reversed_at')
            ->where('paid_at', '>=', $previousFrom)
            ->where('paid_at', '<', $until)
            ->selectRaw(
                'SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN amount ELSE 0 END) AS current_amount,'
                .' SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN amount ELSE 0 END) AS previous_amount',
                [$from, $until, $previousFrom, $previousUntil],
            )
            ->first();

        $balances = $this->balancesAsAt($period->asAt(), $period->previousAsAt());

        return [
            'invoicedNet' => $this->compare($invoiced->current_net, $invoiced->previous_net),
            'invoicedGross' => $this->compare($invoiced->current_gross, $invoiced->previous_gross),
            'collected' => $this->compare($collected->current_amount, $collected->previous_amount),
            'outstanding' => $this->compare($balances['current']['outstanding'], $balances['previous']['outstanding']),
            'overdue' => $this->compare($balances['current']['overdue'], $balances['previous']['overdue']),
        ];
    }

    /**
     * Invoiced (net and gross) and collected amounts per day, week or month, with empty buckets filled.
     *
     * @return array<int, array{key: string, label: string, invoicedNet: string, invoicedGross: string, collected: string}>
     */
    public function series(RevenuePeriod $period, string $granularity): array
    {
        [$from, $until] = $this->bounds($period->from, $period->to);

        $invoicedByDay = DB::table('invoices')
            ->whereIn('status', self::BILLED_STATUSES)
            ->where('issue_date', '>=', $from)
            ->where('issue_date', '<', $until)
            ->selectRaw('DATE(issue_date) AS day, SUM(subtotal) AS net, SUM(total) AS gross')
            ->groupByRaw('DATE(issue_date)')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        $collectedByDay = DB::table('payments')
            ->whereNull('reversed_at')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until)
            ->selectRaw('DATE(paid_at) AS day, SUM(amount) AS amount')
            ->groupByRaw('DATE(paid_at)')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        $buckets = [];

        for ($day = $period->from; $day->lessThanOrEqualTo($period->to); $day = $day->addDay()) {
            $date = $day->toDateString();
            [$key, $label] = $this->bucketFor($day, $period, $granularity);
            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'invoicedNet' => '0.00', 'invoicedGross' => '0.00', 'collected' => '0.00'];

            if ($invoicedByDay->has($date)) {
                $buckets[$key]['invoicedNet'] = bcadd($buckets[$key]['invoicedNet'], Money::fromAggregate($invoicedByDay[$date]->net), 2);
                $buckets[$key]['invoicedGross'] = bcadd($buckets[$key]['invoicedGross'], Money::fromAggregate($invoicedByDay[$date]->gross), 2);
            }

            if ($collectedByDay->has($date)) {
                $buckets[$key]['collected'] = bcadd($buckets[$key]['collected'], Money::fromAggregate($collectedByDay[$date]->amount), 2);
            }
        }

        return array_values($buckets);
    }

    /**
     * Units and net revenue per product. Each invoice's net subtotal is shared across its lines in proportion
     * to the line totals, so product nets reconcile to invoiced net whether prices include VAT or not.
     *
     * @return array{rows: array<int, array{productId: ?int, name: string, sku: ?string, units: int, net: string, share: string}>, totalNet: string, totalUnits: int}
     */
    public function byProduct(RevenuePeriod $period): array
    {
        [$from, $until] = $this->bounds($period->from, $period->to);

        $lineTotals = DB::table('invoice_items')
            ->selectRaw('invoice_id, SUM(line_total) AS lines_total')
            ->groupBy('invoice_id');

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->joinSub($lineTotals, 'line_totals', 'line_totals.invoice_id', '=', 'invoice_items.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereIn('invoices.status', self::BILLED_STATUSES)
            ->where('invoices.issue_date', '>=', $from)
            ->where('invoices.issue_date', '<', $until)
            ->groupBy('invoice_items.product_id', 'products.name', 'products.unit_type')
            ->selectRaw(
                'invoice_items.product_id AS product_id, products.name AS name, products.unit_type AS sku,'
                .' SUM(invoice_items.quantity) AS units,'
                .' SUM(CASE WHEN line_totals.lines_total = 0 THEN 0 ELSE invoice_items.line_total * invoices.subtotal / line_totals.lines_total END) AS net'
            )
            ->get();

        $totalNet = Money::sum($rows->map(fn (object $row): string => Money::fromAggregate($row->net))->all());

        $products = $rows
            ->map(fn (object $row): array => [
                'productId' => $row->product_id === null ? null : (int) $row->product_id,
                'name' => (string) ($row->name ?? 'Removed product'),
                'sku' => $row->sku,
                'units' => (int) $row->units,
                'net' => $net = Money::fromAggregate($row->net),
                'share' => $this->share($net, $totalNet),
            ])
            ->sort(fn (array $a, array $b): int => bccomp($b['net'], $a['net'], 2) ?: strcmp($a['name'], $b['name']))
            ->values()
            ->all();

        return ['rows' => $products, 'totalNet' => $totalNet, 'totalUnits' => (int) $rows->sum('units')];
    }

    /**
     * The ten customers invoiced the most (net) in the period, with what each owes today.
     *
     * @return array<int, array{customerId: int, name: string, invoices: int, net: string, gross: string, outstanding: string}>
     */
    public function topCustomers(RevenuePeriod $period, int $limit = 10): array
    {
        [$from, $until] = $this->bounds($period->from, $period->to);

        $outstanding = DB::table('invoices')
            ->whereIn('status', self::OPEN_STATUSES)
            ->selectRaw('customer_id, SUM(total - amount_paid) AS outstanding')
            ->groupBy('customer_id');

        return DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoinSub($outstanding, 'balances', 'balances.customer_id', '=', 'invoices.customer_id')
            ->whereIn('invoices.status', self::BILLED_STATUSES)
            ->where('invoices.issue_date', '>=', $from)
            ->where('invoices.issue_date', '<', $until)
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw(
                'customers.id AS customer_id, customers.name AS name, COUNT(invoices.id) AS invoices,'
                .' SUM(invoices.subtotal) AS net, SUM(invoices.total) AS gross, MAX(balances.outstanding) AS outstanding'
            )
            ->orderByDesc('net')
            ->orderBy('customers.name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'customerId' => (int) $row->customer_id,
                'name' => (string) $row->name,
                'invoices' => (int) $row->invoices,
                'net' => Money::fromAggregate($row->net),
                'gross' => Money::fromAggregate($row->gross),
                'outstanding' => Money::fromAggregate($row->outstanding),
            ])
            ->all();
    }

    /**
     * Open balances per customer by days since the invoice was issued, as of today.
     *
     * @return array{rows: array<int, array{customerId: int, name: string, current: string, days31to60: string, days61to90: string, over90: string, total: string}>, totals: array{current: string, days31to60: string, days61to90: string, over90: string, total: string}}
     */
    public function aging(CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $day30 = $today->subDays(30)->toDateString();
        $day60 = $today->subDays(60)->toDateString();
        $day90 = $today->subDays(90)->toDateString();
        $balance = '(invoices.total - invoices.amount_paid)';

        $rows = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereIn('invoices.status', self::OPEN_STATUSES)
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw(
                'customers.id AS customer_id, customers.name AS name,'
                ." SUM(CASE WHEN invoices.issue_date >= ? THEN {$balance} ELSE 0 END) AS current_amount,"
                ." SUM(CASE WHEN invoices.issue_date >= ? AND invoices.issue_date < ? THEN {$balance} ELSE 0 END) AS days31to60,"
                ." SUM(CASE WHEN invoices.issue_date >= ? AND invoices.issue_date < ? THEN {$balance} ELSE 0 END) AS days61to90,"
                ." SUM(CASE WHEN invoices.issue_date < ? THEN {$balance} ELSE 0 END) AS over90,"
                ." SUM({$balance}) AS total",
                [$day30, $day60, $day30, $day90, $day60, $day90],
            )
            ->havingRaw("SUM({$balance}) > 0")
            ->get()
            ->map(fn (object $row): array => [
                'customerId' => (int) $row->customer_id,
                'name' => (string) $row->name,
                'current' => Money::fromAggregate($row->current_amount),
                'days31to60' => Money::fromAggregate($row->days31to60),
                'days61to90' => Money::fromAggregate($row->days61to90),
                'over90' => Money::fromAggregate($row->over90),
                'total' => Money::fromAggregate($row->total),
            ])
            ->sortByDesc(fn (array $row): float => (float) $row['total'])
            ->values()
            ->all();

        $totals = [];

        foreach ([...array_keys(self::AGING_BUCKETS), 'total'] as $column) {
            $totals[$column] = Money::sum(array_column($rows, $column));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Expected revenue for the next seven days: forecast units × each product's current list price.
     * Days without a stored forecast contribute nothing and are reported as missing.
     *
     * @return array{days: array<int, array{date: string, label: string}>, rows: array<int, array{productId: int, name: string, sku: string, unitPrice: string, daily: array<string, ?string>, units: string, revenue: string, coveredDays: int}>, totalRevenue: string, coveredProducts: int}
     */
    public function forecastEstimate(CarbonImmutable $today): array
    {
        $first = $today->startOfDay()->addDay();
        $last = $first->addDays(self::FORECAST_DAYS - 1);
        $days = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'label' => $this->dates->formatDate($day)];
        }

        $products = Product::query()->orderBy('unit_type')->orderBy('id')->get(['id', 'name', 'unit_type', 'unit_price_kes']);

        $latest = ForecastRecord::query()
            ->whereIn('product_id', $products->modelKeys())
            ->whereDate('forecast_date', '>=', $first->toDateString())
            ->whereDate('forecast_date', '<=', $last->toDateString())
            ->latestGenerated()
            ->get(['id', 'product_id', 'forecast_date', 'predicted_demand', 'generated_at'])
            ->unique(fn (ForecastRecord $record): string => $record->product_id.'|'.$record->forecast_date->toDateString())
            ->keyBy(fn (ForecastRecord $record): string => $record->product_id.'|'.$record->forecast_date->toDateString());

        $rows = $products->map(function (Product $product) use ($days, $latest): array {
            $unitPrice = Money::fromAggregate($product->unit_price_kes);
            $daily = [];
            $units = '0.00';

            foreach ($days as $day) {
                $record = $latest->get($product->id.'|'.$day['date']);
                $daily[$day['date']] = $record === null ? null : Money::fromAggregate($record->predicted_demand);

                if ($daily[$day['date']] !== null) {
                    $units = bcadd($units, $daily[$day['date']], 2);
                }
            }

            $coveredDays = count(array_filter($daily, fn (?string $value): bool => $value !== null));

            return [
                'productId' => $product->id,
                'name' => $product->name,
                'sku' => $product->unit_type,
                'unitPrice' => $unitPrice,
                'daily' => $daily,
                'units' => $units,
                'revenue' => bcround(bcmul($units, $unitPrice, 4), 2),
                'coveredDays' => $coveredDays,
            ];
        })->values()->all();

        return [
            'days' => $days,
            'rows' => $rows,
            'totalRevenue' => Money::sum(array_column($rows, 'revenue')),
            'coveredProducts' => count(array_filter($rows, fn (array $row): bool => $row['coveredDays'] > 0)),
        ];
    }

    /**
     * Outstanding and overdue balances at two dates: totals of billed invoices issued by then, less the live
     * payments received on them by then. Overdue is the same for invoices whose due date had already passed.
     *
     * @return array{current: array{outstanding: string, overdue: string}, previous: array{outstanding: string, overdue: string}}
     */
    private function balancesAsAt(CarbonImmutable $asAt, CarbonImmutable $previousAsAt): array
    {
        $current = [$asAt->toDateString(), $asAt->addDay()->toDateString()];
        $previous = [$previousAsAt->toDateString(), $previousAsAt->addDay()->toDateString()];

        $totals = DB::table('invoices')
            ->whereIn('status', self::BILLED_STATUSES)
            ->selectRaw(
                'SUM(CASE WHEN issue_date < ? THEN total ELSE 0 END) AS current_billed,'
                .' SUM(CASE WHEN issue_date < ? AND due_date < ? THEN total ELSE 0 END) AS current_due,'
                .' SUM(CASE WHEN issue_date < ? THEN total ELSE 0 END) AS previous_billed,'
                .' SUM(CASE WHEN issue_date < ? AND due_date < ? THEN total ELSE 0 END) AS previous_due',
                [$current[1], $current[1], $current[0], $previous[1], $previous[1], $previous[0]],
            )
            ->first();

        $paid = $this->livePaymentsOnBilledInvoices()
            ->selectRaw(
                'SUM(CASE WHEN payments.paid_at < ? AND invoices.issue_date < ? THEN payments.amount ELSE 0 END) AS current_paid,'
                .' SUM(CASE WHEN payments.paid_at < ? AND invoices.issue_date < ? AND invoices.due_date < ? THEN payments.amount ELSE 0 END) AS current_due_paid,'
                .' SUM(CASE WHEN payments.paid_at < ? AND invoices.issue_date < ? THEN payments.amount ELSE 0 END) AS previous_paid,'
                .' SUM(CASE WHEN payments.paid_at < ? AND invoices.issue_date < ? AND invoices.due_date < ? THEN payments.amount ELSE 0 END) AS previous_due_paid',
                [$current[1], $current[1], $current[1], $current[1], $current[0], $previous[1], $previous[1], $previous[1], $previous[1], $previous[0]],
            )
            ->first();

        return [
            'current' => [
                'outstanding' => bcsub(Money::fromAggregate($totals->current_billed), Money::fromAggregate($paid->current_paid), 2),
                'overdue' => bcsub(Money::fromAggregate($totals->current_due), Money::fromAggregate($paid->current_due_paid), 2),
            ],
            'previous' => [
                'outstanding' => bcsub(Money::fromAggregate($totals->previous_billed), Money::fromAggregate($paid->previous_paid), 2),
                'overdue' => bcsub(Money::fromAggregate($totals->previous_due), Money::fromAggregate($paid->previous_due_paid), 2),
            ],
        ];
    }

    private function livePaymentsOnBilledInvoices(): Builder
    {
        return DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereNull('payments.reversed_at')
            ->whereIn('invoices.status', self::BILLED_STATUSES);
    }

    /**
     * Half-open bounds [from, day after to) as plain date strings. They compare correctly against DATE columns
     * (MySQL), date strings stored with a midnight time (SQLite) and DATETIME columns alike.
     *
     * @return array{string, string}
     */
    private function bounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString(), $to->addDay()->toDateString()];
    }

    /**
     * @return array{current: string, previous: string, change: ?string}
     */
    private function compare(int|float|string|null $current, int|float|string|null $previous): array
    {
        $current = Money::fromAggregate($current);
        $previous = Money::fromAggregate($previous);

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => bccomp($previous, '0', 2) === 0
                ? null
                : bcround(bcdiv(bcmul(bcsub($current, $previous, 2), '100', 6), $previous, 6), 1),
        ];
    }

    private function share(string $amount, string $total): string
    {
        return bccomp($total, '0', 2) === 0 ? '0.0' : bcround(bcdiv(bcmul($amount, '100', 6), $total, 6), 1);
    }

    /**
     * @return array{string, string} bucket key and label
     */
    private function bucketFor(CarbonImmutable $day, RevenuePeriod $period, string $granularity): array
    {
        return match ($granularity) {
            'week' => [
                $start = $day->startOfWeek(CarbonImmutable::MONDAY)->max($period->from)->toDateString(),
                'Week of '.$this->dates->formatDate($start),
            ],
            'month' => [$day->format('Y-m'), $day->format('M Y')],
            default => [$day->toDateString(), $this->dates->formatDate($day)],
        };
    }
}
