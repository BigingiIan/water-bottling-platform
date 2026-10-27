<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

class StockAvailability
{
    /**
     * On-hand units, units reserved by other confirmed orders, and what is left to promise, per product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{onHand: int, reserved: int, available: int}>
     */
    public function forProducts(array $productIds, ?int $excludeOrderId = null): array
    {
        $onHand = DB::table('warehouse_stock')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(current_level) as units')
            ->pluck('units', 'product_id');
        $reserved = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $productIds)
            ->where('orders.status', OrderStatus::Confirmed->value)
            ->when($excludeOrderId !== null, fn ($query) => $query->where('orders.id', '!=', $excludeOrderId))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as units')
            ->pluck('units', 'product_id');

        $availability = [];

        foreach ($productIds as $productId) {
            $productOnHand = (int) ($onHand[$productId] ?? 0);
            $productReserved = (int) ($reserved[$productId] ?? 0);
            $availability[$productId] = [
                'onHand' => $productOnHand,
                'reserved' => $productReserved,
                'available' => max(0, $productOnHand - $productReserved),
            ];
        }

        return $availability;
    }
}
