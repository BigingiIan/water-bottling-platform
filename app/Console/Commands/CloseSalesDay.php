<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Forecasting\DailyContext;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sales:close-day {date? : Y-m-d, defaults to yesterday in the configured timezone}')]
#[Description("Mark a day's sales as complete for forecasting once no order dated that day is still open")]
class CloseSalesDay extends Command
{
    public function handle(DailyContext $context, SettingsService $settings): int
    {
        $timezone = (string) $settings->get('organisation.timezone', config('app.timezone'));
        $date = $this->argument('date') ?? CarbonImmutable::now($timezone)->subDay()->toDateString();
        $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsedDate === false || $parsedDate->toDateString() !== $date) {
            $this->error("{$date} is not a valid Y-m-d date.");

            return self::INVALID;
        }

        $openOrdersByProduct = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.order_date', $date)
            ->whereIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Confirmed->value])
            ->orderBy('orders.order_number')
            ->get(['order_items.product_id', 'orders.order_number', 'orders.status'])
            ->groupBy('product_id');
        $existingFeatures = DB::table('daily_sku_features')->whereDate('date', $date)->get()->keyBy('product_sku');
        $rows = [];
        $closed = 0;
        $products = Product::query()->orderBy('unit_type')->get(['id', 'unit_type']);

        foreach ($products as $product) {
            $sku = $product->unit_type;
            $openOrders = $openOrdersByProduct->get($product->id, collect());
            $existing = $existingFeatures->get($sku);

            try {
                $temperature = $context->temperature($sku, $date);
            } catch (Throwable $exception) {
                $rows[] = [$sku, 'not closed', $exception->getMessage()];

                continue;
            }

            $isCovered = $openOrders->isEmpty();
            $promotionAssumed = $existing?->is_promotion === null;

            DB::table('daily_sku_features')->upsert([[
                'product_sku' => $sku,
                'date' => $date,
                'is_promotion' => $promotionAssumed ? false : (bool) $existing->is_promotion,
                'is_public_holiday' => $context->isPublicHoliday($date),
                'is_school_term' => $context->isSchoolTerm($date),
                'temperature_c' => $temperature,
                'sales_data_covered' => $isCovered,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['product_sku', 'date'], ['is_promotion', 'is_public_holiday', 'is_school_term', 'temperature_c', 'sales_data_covered', 'updated_at']);

            if ($isCovered) {
                $closed++;
                $rows[] = [$sku, 'closed', $promotionAssumed ? 'No promotion flag was set; recorded as no promotion.' : ''];
            } else {
                $rows[] = [$sku, 'not closed', $openOrders->count().' open '.str('order')->plural($openOrders->count()).': '
                    .$openOrders->map(fn (object $order): string => "{$order->order_number} ({$order->status})")->implode(', ')];
            }
        }

        $this->table(['SKU', 'Result', 'Reason'], $rows);
        $this->info("Closed {$closed} of {$products->count()} products for {$date}.");

        return self::SUCCESS;
    }
}
