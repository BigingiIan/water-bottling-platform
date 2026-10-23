<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ForecastRecord;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Revenue\RevenuePeriod;
use App\Services\Revenue\RevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 09:00:00');
        $this->customer = Customer::factory()->create(['name' => 'Naivas Thika']);
    }

    public function test_invoiced_revenue_counts_billed_invoices_by_issue_date_net_and_gross(): void
    {
        $this->invoice('2026-10-05', '1000.00', status: 'issued');
        $this->invoice('2026-10-23', '500.00', status: 'paid');
        $this->invoice('2026-10-10', '9999.00', status: 'void');
        $this->invoice(null, '777.00', status: 'draft');
        $this->invoice('2026-09-30', '300.00', status: 'partially_paid');
        $this->invoice('2026-09-15', '200.00', status: 'issued');

        $kpis = $this->report()->kpis($this->period('this_month'));

        $this->assertSame(['current' => '1500.00', 'previous' => '200.00', 'change' => '650.0'], $kpis['invoicedNet']);
        $this->assertSame(['current' => '1740.00', 'previous' => '232.00', 'change' => '650.0'], $kpis['invoicedGross']);

        $lastMonth = $this->report()->kpis($this->period('last_month'));
        $this->assertSame(['current' => '500.00', 'previous' => '0.00', 'change' => null], $lastMonth['invoicedNet']);
    }

    public function test_collected_counts_live_payments_by_paid_at(): void
    {
        $october = $this->invoice('2026-10-01', '1000.00');
        $september = $this->invoice('2026-09-01', '1000.00');
        $this->payment($october, '100.00', '2026-10-01 00:00:00');
        $this->payment($october, '50.00', '2026-10-23 23:59:59');
        $this->payment($october, '70.00', '2026-10-10 10:00:00', reversed: true);
        $this->payment($september, '40.00', '2026-09-23 12:00:00');
        $this->payment($september, '30.00', '2026-09-24 08:00:00');

        $kpis = $this->report()->kpis($this->period('this_month'));

        $this->assertSame(['current' => '150.00', 'previous' => '40.00', 'change' => '275.0'], $kpis['collected']);
    }

    public function test_outstanding_and_overdue_are_open_balances_as_at_the_period_end(): void
    {
        $partlyPaid = $this->invoice('2026-10-01', '1000.00', status: 'partially_paid', dueDate: '2026-10-31', amountPaid: '160.00');
        $this->payment($partlyPaid, '160.00', '2026-10-02 10:00:00');
        $overdue = $this->invoice('2026-09-01', '500.00', dueDate: '2026-10-01');
        $this->payment($overdue, '100.00', '2026-10-05 10:00:00', reversed: true);
        $this->invoice('2026-09-23', '200.00', dueDate: '2026-10-23');
        $paid = $this->invoice('2026-09-10', '100.00', status: 'paid', dueDate: '2026-10-10', amountPaid: '116.00');
        $this->payment($paid, '116.00', '2026-09-20 10:00:00');
        $this->invoice('2026-10-02', '5000.00', status: 'void');

        $kpis = $this->report()->kpis($this->period('this_month'));

        $openBalance = Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->get()
            ->reduce(fn (string $sum, Invoice $invoice): string => bcadd($sum, $invoice->balance(), 2), '0.00');
        $this->assertSame('1812.00', $openBalance);
        $this->assertSame(['current' => '1812.00', 'previous' => '812.00', 'change' => '123.2'], $kpis['outstanding']);
        $this->assertSame(['current' => '580.00', 'previous' => '0.00', 'change' => null], $kpis['overdue'], 'Due today is not overdue; previous as at 23 Sep had nothing past due.');
    }

    public function test_periods_compare_like_for_like(): void
    {
        $at = fn (string $today): CarbonImmutable => CarbonImmutable::parse($today);
        $range = fn (RevenuePeriod $period): array => [$period->from->toDateString(), $period->to->toDateString(), $period->previousFrom->toDateString(), $period->previousTo->toDateString()];

        $this->assertSame(['2026-10-01', '2026-10-23', '2026-09-01', '2026-09-23'], $range(RevenuePeriod::fromInput('this_month', null, null, $at('2026-10-23'))));
        $this->assertSame(['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'], $range(RevenuePeriod::fromInput('this_month', null, null, $at('2026-10-31'))));
        $this->assertSame(['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'], $range(RevenuePeriod::fromInput('this_month', null, null, $at('2026-03-31'))));
        $this->assertSame(['2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31'], $range(RevenuePeriod::fromInput('last_month', null, null, $at('2026-10-23'))));
        $this->assertSame(['2026-10-01', '2026-10-23', '2026-07-01', '2026-07-23'], $range(RevenuePeriod::fromInput('this_quarter', null, null, $at('2026-10-23'))));
        $this->assertSame(['2026-10-01', '2026-10-10', '2026-09-21', '2026-09-30'], $range(RevenuePeriod::fromInput('custom', '2026-10-01', '2026-10-10', $at('2026-10-23'))));
        $this->assertSame('2026-10-23', RevenuePeriod::fromInput('custom', '2026-10-01', '2026-12-31', $at('2026-10-23'))->asAt()->toDateString());

        foreach ([['2026-10-10', '2026-10-01'], ['2025-01-01', '2026-10-01'], ['2026-02-30', '2026-03-01'], [null, '2026-10-01']] as [$from, $to]) {
            try {
                RevenuePeriod::fromInput('custom', $from, $to, $at('2026-10-23'));
                $this->fail("Range {$from}–{$to} should be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_aging_buckets_split_at_their_boundaries(): void
    {
        foreach ([0, 30, 31, 60, 61, 90, 91] as $daysAgo) {
            $this->invoice(today()->subDays($daysAgo)->toDateString(), '100.00', vatRate: '0');
        }
        $this->invoice(today()->subDays(10)->toDateString(), '100.00', status: 'partially_paid', vatRate: '0', amountPaid: '40.00');
        $otherCustomer = Customer::factory()->create(['name' => 'Quickmart Kilimani']);
        $this->invoice('2026-10-01', '100.00', status: 'paid', customer: $otherCustomer, vatRate: '0', amountPaid: '100.00');
        $this->invoice('2026-06-01', '900.00', status: 'void', customer: $otherCustomer);

        $aging = $this->report()->aging(CarbonImmutable::today());

        $this->assertCount(1, $aging['rows']);
        $this->assertSame(
            ['customerId' => $this->customer->id, 'name' => 'Naivas Thika', 'current' => '260.00', 'days31to60' => '200.00', 'days61to90' => '200.00', 'over90' => '100.00', 'total' => '760.00'],
            $aging['rows'][0],
        );
        $this->assertSame(['current' => '260.00', 'days31to60' => '200.00', 'days61to90' => '200.00', 'over90' => '100.00', 'total' => '760.00'], $aging['totals']);
    }

    public function test_product_revenue_shares_each_invoice_net_across_its_lines(): void
    {
        $water = Product::factory()->create(['name' => 'Bottled Water 1L']);
        $jar = Product::factory()->create(['name' => 'Water Jar 20L']);
        $this->invoice('2026-10-02', '500.00', lines: [[$water, 2, '100.00'], [$jar, 1, '300.00']]);
        $this->invoice('2026-10-03', '100.00', inclusive: true, lines: [[$water, 1, '116.00']]);
        $this->invoice('2026-10-04', '86.21', inclusive: true, lines: [[$water, 1, '50.00'], [$jar, 1, '50.00']]);
        $this->invoice('2026-10-05', '1000.00', status: 'void', lines: [[$jar, 10, '100.00']]);

        $products = $this->report()->byProduct($this->period('this_month'));
        $invoicedNet = $this->report()->kpis($this->period('this_month'))['invoicedNet']['current'];

        $this->assertSame([
            ['productId' => $water->id, 'name' => 'Bottled Water 1L', 'sku' => $water->unit_type, 'units' => 4, 'net' => '343.11', 'share' => '50.0'],
            ['productId' => $jar->id, 'name' => 'Water Jar 20L', 'sku' => $jar->unit_type, 'units' => 2, 'net' => '343.11', 'share' => '50.0'],
        ], $products['rows']);
        $this->assertSame('686.21', $invoicedNet);
        $this->assertSame(-1, bccomp(ltrim(bcsub($products['totalNet'], $invoicedNet, 2), '-'), '0.02', 2), 'Product nets reconcile to invoiced net within rounding.');
    }

    public function test_top_customers_are_limited_to_ten_and_carry_todays_balance(): void
    {
        $customers = collect(range(1, 12))->map(fn (int $rank): Customer => Customer::factory()->create(['name' => sprintf('Customer %02d', $rank)]));
        $customers->each(fn (Customer $customer, int $index) => $this->invoice('2026-10-0'.($index % 9 + 1), (string) (($index + 1) * 100).'.00', status: 'paid', customer: $customer, amountPaid: bcmul((string) (($index + 1) * 100), '1.16', 2)));
        $this->invoice('2026-08-01', '50.00', customer: $customers->last());

        $top = $this->report()->topCustomers($this->period('this_month'));

        $this->assertCount(10, $top);
        $this->assertSame('Customer 12', $top[0]['name']);
        $this->assertSame('1200.00', $top[0]['net']);
        $this->assertSame('58.00', $top[0]['outstanding'], 'Outstanding includes older open invoices.');
        $this->assertSame('Customer 03', $top[9]['name']);
        $this->assertSame('0.00', $top[9]['outstanding']);
    }

    public function test_forecast_estimate_uses_the_newest_record_and_flags_missing_forecasts(): void
    {
        $water = Product::factory()->create(['unit_type' => '1L', 'name' => 'Bottled Water 1L', 'unit_price_kes' => '45.00']);
        $jar = Product::factory()->create(['unit_type' => '20L', 'name' => 'Water Jar 20L', 'unit_price_kes' => '220.00']);
        $this->forecast($water, '2026-10-24', '100.00', 'v1', '2026-10-22 18:00:00');
        $this->forecast($water, '2026-10-24', '120.00', 'v2', '2026-10-23 06:00:00');
        $this->forecast($water, '2026-10-25', '80.50', 'v2', '2026-10-23 06:00:00');
        $this->forecast($water, '2026-10-23', '999.00', 'v2', '2026-10-22 06:00:00');
        $this->forecast($water, '2026-10-31', '999.00', 'v2', '2026-10-23 06:00:00');

        $estimate = $this->report()->forecastEstimate(CarbonImmutable::today());

        $this->assertSame(['2026-10-24', '2026-10-30'], [$estimate['days'][0]['date'], $estimate['days'][6]['date']]);
        [$waterRow, $jarRow] = $estimate['rows'];
        $this->assertSame(['2026-10-24' => '120.00', '2026-10-25' => '80.50'], array_filter($waterRow['daily']));
        $this->assertSame(['units' => '200.50', 'revenue' => '9022.50', 'coveredDays' => 2], ['units' => $waterRow['units'], 'revenue' => $waterRow['revenue'], 'coveredDays' => $waterRow['coveredDays']]);
        $this->assertSame([0, '0.00'], [$jarRow['coveredDays'], $jarRow['revenue']]);
        $this->assertSame([null], array_values(array_unique($jarRow['daily'])));
        $this->assertSame(['9022.50', 1], [$estimate['totalRevenue'], $estimate['coveredProducts']]);
    }

    public function test_series_buckets_by_day_week_and_month_with_empty_buckets(): void
    {
        $monday = $this->invoice('2026-10-05', '100.00');
        $this->invoice('2026-10-06', '50.00');
        $this->payment($monday, '30.00', '2026-10-20 15:00:00');
        $this->payment($monday, '99.00', '2026-10-21 15:00:00', reversed: true);
        $period = $this->period('this_month');

        $days = $this->report()->series($period, 'day');
        $this->assertCount(23, $days);
        $this->assertSame(['key' => '2026-10-05', 'invoicedNet' => '100.00', 'invoicedGross' => '116.00', 'collected' => '0.00'], array_diff_key($days[4], ['label' => true]));
        $this->assertSame('0.00', $days[0]['invoicedNet']);

        $weeks = $this->report()->series($period, 'week');
        $this->assertSame(['2026-10-01', '2026-10-05', '2026-10-12', '2026-10-19'], array_column($weeks, 'key'));
        $this->assertSame(['0.00', '150.00', '0.00', '0.00'], array_column($weeks, 'invoicedNet'));
        $this->assertSame(['0.00', '0.00', '0.00', '30.00'], array_column($weeks, 'collected'));

        $months = $this->report()->series($period, 'month');
        $this->assertSame([['key' => '2026-10', 'label' => 'Oct 2026', 'invoicedNet' => '150.00', 'invoicedGross' => '174.00', 'collected' => '30.00']], $months);
    }

    public function test_query_count_does_not_grow_with_data(): void
    {
        $product = Product::factory()->create();
        $period = $this->period('this_month');
        $this->runEverything($period);

        $this->invoice('2026-10-02', '100.00', lines: [[$product, 1, '100.00']]);
        $small = $this->countQueries(fn () => $this->runEverything($period));
        $this->assertSame(11, $small, 'kpis 4, series 2, products 1, customers 1, aging 1, forecast 2.');

        foreach (range(1, 60) as $index) {
            $customer = Customer::factory()->create();
            $invoice = $this->invoice('2026-'.($index % 2 === 0 ? '10' : '08').'-'.str_pad((string) ($index % 20 + 1), 2, '0', STR_PAD_LEFT), '100.00', customer: $customer, lines: [[$product, 1, '100.00']]);
            $this->payment($invoice, '10.00', '2026-10-15 10:00:00');
            $this->forecast($product, CarbonImmutable::today()->addDays($index % 7 + 1)->toDateString(), '10.00', "v{$index}", '2026-10-23 06:00:00');
        }

        $this->assertSame($small, $this->countQueries(fn () => $this->runEverything($period)));
    }

    private function runEverything(RevenuePeriod $period): void
    {
        $report = $this->report();
        $report->kpis($period);
        $report->series($period, 'week');
        $report->byProduct($period);
        $report->topCustomers($period);
        $report->aging($period->today);
        $report->forecastEstimate($period->today);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function report(): RevenueReport
    {
        return app(RevenueReport::class);
    }

    private function period(string $preset): RevenuePeriod
    {
        return RevenuePeriod::fromInput($preset, null, null, CarbonImmutable::today());
    }

    /**
     * Builds an invoice row directly, as the invoicing services would have left it.
     *
     * @param  array<int, array{Product, int, string}>  $lines  [product, quantity, unit price]
     */
    private function invoice(
        ?string $issueDate,
        string $subtotal,
        string $status = 'issued',
        ?Customer $customer = null,
        string $vatRate = '16',
        bool $inclusive = false,
        ?string $dueDate = null,
        string $amountPaid = '0.00',
        array $lines = [],
    ): Invoice {
        $customer ??= $this->customer;
        $vat = bcround(bcmul($subtotal, bcdiv($vatRate, '100', 4), 4), 2);
        $total = $inclusive
            ? array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, bcmul($line[2], (string) $line[1], 2), 2), '0.00')
            : bcadd($subtotal, $vat, 2);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => 'delivered']);

        $invoice = Invoice::query()->forceCreate([
            'invoice_number' => $issueDate === null ? null : 'INV-TEST-'.$order->id,
            'order_id' => $order->id,
            'orders_invoice_key' => $status === 'void' ? null : $order->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => $dueDate ?? ($issueDate === null ? null : Carbon::parse($issueDate)->addDays(30)->toDateString()),
            'prices_include_vat' => $inclusive,
            'vat_rate' => $vatRate,
            'subtotal' => $subtotal,
            'vat_amount' => bcsub($total, $subtotal, 2),
            'total' => $total,
            'amount_paid' => $amountPaid,
        ]);

        foreach ($lines as [$product, $quantity, $unitPrice]) {
            DB::table('invoice_items')->insert([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => bcmul($unitPrice, (string) $quantity, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invoice;
    }

    private function payment(Invoice $invoice, string $amount, string $paidAt, bool $reversed = false): Payment
    {
        return Payment::query()->forceCreate([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'paid_at' => $paidAt,
            'reversed_at' => $reversed ? '2026-10-22 12:00:00' : null,
            'reversal_reason' => $reversed ? 'Test reversal' : null,
        ]);
    }

    private function forecast(Product $product, string $date, string $units, string $modelVersion, string $generatedAt): void
    {
        ForecastRecord::query()->forceCreate([
            'product_id' => $product->id,
            'forecast_date' => $date,
            'predicted_demand' => $units,
            'horizon_days' => 1,
            'model_version' => $modelVersion,
            'generated_at' => $generatedAt,
        ]);
    }
}
