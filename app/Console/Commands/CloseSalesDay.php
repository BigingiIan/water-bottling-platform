<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Calendar\ContextCalendar;
use App\Services\Calendar\TemperatureSource;
use App\Services\Forecasting\ForecastReadiness;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('sales:close-day
    {date? : Y-m-d, defaults to yesterday in the configured timezone}
    {--catch-up : Also re-check every still-uncovered day in the forecast readiness window before that date}')]
#[Description("Mark a day's sales as complete for forecasting once no order dated that day is still open")]
class CloseSalesDay extends Command
{
    public function __construct(
        private ContextCalendar $calendar,
        private TemperatureSource $temperatureSource,
    ) {
        parent::__construct();
    }

    public function handle(SettingsService $settings, ForecastReadiness $readiness): int
    {
        $timezone = (string) $settings->get('organisation.timezone', config('app.timezone'));
        $date = $this->argument('date') ?? CarbonImmutable::now($timezone)->subDay()->toDateString();
        $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsedDate === false || $parsedDate->toDateString() !== $date) {
            $this->error("{$date} is not a valid Y-m-d date.");

            return self::INVALID;
        }

        $products = Product::query()->orderBy('unit_type')->get(['id', 'unit_type']);

        if ($this->option('catch-up')) {
            foreach ($this->uncoveredDaysBefore($parsedDate, $readiness->requiredDays(), $products) as $earlierDay) {
                $result = $this->closeDay($earlierDay, $products);
                $this->line("Re-checked {$earlierDay->toDateString()}: closed {$result['closed']} of {$products->count()} products.");
            }
        }

        $result = $this->closeDay($parsedDate, $products);

        $this->table(['SKU', 'Result', 'Reason'], $result['rows']);
        $this->info("Closed {$result['closed']} of {$products->count()} products for {$date}.");

        return self::SUCCESS;
    }

    /**
     * Earlier days in the forecast readiness window that at least one product still has uncovered, oldest first.
     *
     * A day left open by a late order is closed here once that order is delivered or cancelled; otherwise it
     * would block forecasting until it aged out of the window. Days before the first recorded order are left
     * alone: they are not sales history, so they must not be closed as zero-sales days.
     *
     * @param  Collection<int, Product>  $products
     * @return list<CarbonImmutable>
     */
    private function uncoveredDaysBefore(CarbonImmutable $date, int $windowDays, Collection $products): array
    {
        $firstOrderDate = DB::table('orders')->min('order_date');

        if ($firstOrderDate === null || $products->isEmpty()) {
            return [];
        }

        $windowStart = $date->subDays($windowDays);
        $firstOrderDay = CarbonImmutable::parse(substr((string) $firstOrderDate, 0, 10));
        $from = $firstOrderDay->greaterThan($windowStart) ? $firstOrderDay : $windowStart;
        $coveredProductsByDay = DB::table('daily_sku_features')
            ->whereIn('product_sku', $products->pluck('unit_type'))
            ->whereBetween('date', [$from->toDateString(), $date->subDay()->toDateString()])
            ->where('sales_data_covered', true)
            ->get(['date'])
            ->countBy(fn (object $feature): string => substr((string) $feature->date, 0, 10));
        $days = [];

        for ($day = $from; $day->lessThan($date); $day = $day->addDay()) {
            if ($coveredProductsByDay->get($day->toDateString(), 0) < $products->count()) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Records each product's context and coverage for one day. Closing only upserts daily_sku_features on
     * (product_sku, date), so closing the same day again rewrites the same rows and duplicates nothing.
     *
     * @param  Collection<int, Product>  $products
     * @return array{closed: int, rows: list<array{0: string, 1: string, 2: string}>}
     */
    private function closeDay(CarbonImmutable $day, Collection $products): array
    {
        $date = $day->toDateString();
        $openOrdersByProduct = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.order_date', $date)
            ->whereIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Confirmed->value])
            ->orderBy('orders.order_number')
            ->get(['order_items.product_id', 'orders.order_number', 'orders.status'])
            ->groupBy('product_id');
        $rows = [];
        $closed = 0;

        foreach ($products as $product) {
            $sku = $product->unit_type;
            $openOrders = $openOrdersByProduct->get($product->id, collect());
            $temperature = $this->temperatureSource->temperatureFor($sku, $day);

            if ($temperature === null) {
                $rows[] = [$sku, 'not closed', (string) $this->temperatureSource->unavailableReason($sku)];

                continue;
            }

            $isCovered = $openOrders->isEmpty();

            // Flags come from the Business Calendar, so closing a day records what was actually planned.
            DB::table('daily_sku_features')->upsert([[
                'product_sku' => $sku,
                'date' => $date,
                ...$this->calendar->flagsFor($product->id, $day),
                'temperature_c' => $temperature,
                'temperature_source' => $this->temperatureSource->name(),
                'sales_data_covered' => $isCovered,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['product_sku', 'date'], ['is_promotion', 'is_public_holiday', 'is_school_term', 'temperature_c', 'temperature_source', 'sales_data_covered', 'updated_at']);

            if ($isCovered) {
                $closed++;
                $rows[] = [$sku, 'closed', ''];
            } else {
                $rows[] = [$sku, 'not closed', $openOrders->count().' open '.str('order')->plural($openOrders->count()).': '
                    .$openOrders->map(fn (object $order): string => "{$order->order_number} ({$order->status})")->implode(', ')];
            }
        }

        return ['closed' => $closed, 'rows' => $rows];
    }
}
