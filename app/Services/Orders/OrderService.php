<?php

namespace App\Services\Orders;

use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Exceptions\Orders\OrderStockShortageException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private OrderNumberGenerator $numbers,
        private StockAvailability $availability,
        private StockService $stock,
        private NotificationService $notifications,
    ) {}

    /**
     * Creates a draft, or replaces the lines of an existing draft.
     *
     * @param  array<int, int>  $quantities  product id => quantity
     */
    public function saveDraft(?Order $order, Customer $customer, array $quantities, ?string $requestedDate, ?string $notes, User $user): Order
    {
        $quantities = array_filter($quantities, fn (int $quantity): bool => $quantity !== 0);

        if ($quantities === []) {
            throw new InvalidArgumentException('An order needs at least one line.');
        }

        foreach ($quantities as $quantity) {
            if ($quantity < 1) {
                throw new InvalidArgumentException('Line quantities must be greater than zero.');
            }
        }

        return DB::transaction(function () use ($order, $customer, $quantities, $requestedDate, $notes, $user): Order {
            $isNew = $order === null;

            if ($isNew) {
                $order = Order::query()->create([
                    'order_number' => $this->numbers->next((int) now()->year),
                    'customer_id' => $customer->id,
                    'status' => OrderStatus::Draft,
                    'order_date' => today(),
                    'requested_date' => $requestedDate,
                    'notes' => $notes,
                    'created_by' => $user->id,
                ]);
            } else {
                $order = $this->lock($order);

                if ($order->status !== OrderStatus::Draft) {
                    throw new InvalidArgumentException('Only draft orders can be edited.');
                }

                $order->update(['customer_id' => $customer->id, 'requested_date' => $requestedDate, 'notes' => $notes]);
                $order->items()->delete();
            }

            foreach ($quantities as $productId => $quantity) {
                $order->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
            }

            $this->audit($order, $isNew ? 'orders.created' : 'orders.updated', null, OrderStatus::Draft, $user, [
                'customer_id' => $customer->id,
                'lines' => $quantities,
            ]);

            return $order->refresh()->load('items.product');
        });
    }

    /**
     * Prices every line and reserves it against stock not already promised to other confirmed orders.
     *
     * @throws OrderStockShortageException
     */
    public function confirm(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user): Order {
            $order = $this->lock($order);
            $this->assertTransition($order, OrderStatus::Confirmed);
            $items = $order->items()->with('product')->get();
            $productIds = $items->pluck('product_id')->all();

            // Serialise confirmations that touch the same products so two orders can't reserve the same units.
            Product::query()->whereIn('id', $productIds)->lockForUpdate()->get();
            $availability = $this->availability->forProducts($productIds, $order->id);
            $problems = [];

            foreach ($items as $item) {
                $stock = $availability[$item->product_id];

                if ($item->product->unit_price_kes === null) {
                    $problems[$item->id] = "{$item->product->unit_type}: no price is set for this product.";
                } elseif ($item->quantity > $stock['available']) {
                    $problems[$item->id] = "{$item->product->unit_type}: need ".number_format($item->quantity)
                        .', only '.number_format($stock['available']).' available'
                        .($stock['reserved'] > 0 ? ' ('.number_format($stock['reserved']).' reserved by other orders)' : '')
                        .'.';
                }
            }

            if ($problems !== []) {
                throw new OrderStockShortageException($problems);
            }

            foreach ($items as $item) {
                $unitPrice = Money::normalize((string) $item->product->unit_price_kes);
                $item->update(['unit_price' => $unitPrice, 'line_total' => Money::multiply($item->quantity, $unitPrice)]);
            }

            $order->update(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);
            $this->audit($order, 'orders.status_changed', OrderStatus::Draft, OrderStatus::Confirmed, $user, [
                'total' => Money::sum($items->map(fn (OrderItem $item): string => (string) $item->line_total)->all()),
            ]);

            return $order->refresh()->load('items.product');
        });
    }

    /**
     * Deducts stock oldest batch first and records one sale per stock line used. All or nothing.
     *
     * @throws OrderStockShortageException
     */
    public function dispatch(Order $order, User $user): Order
    {
        try {
            return DB::transaction(function () use ($order, $user): Order {
                $order = $this->lock($order);
                $this->assertTransition($order, OrderStatus::Dispatched);
                $items = $order->items()->with('product')->get();
                $allocations = $this->allocateFifo($items);
                $dispatchedAt = now();

                foreach ($allocations as $allocation) {
                    $this->stock->applyMovement(
                        $allocation['stock'],
                        'sale',
                        -$allocation['quantity'],
                        $order->order_number,
                        'Order dispatch to '.$order->customer->name,
                        $user,
                    );

                    DB::table('sales_transactions')->insert([
                        'customer_id' => $order->customer_id,
                        'stock_id' => $allocation['stock']->id,
                        'quantity' => $allocation['quantity'],
                        'unit_price' => $allocation['item']->unit_price,
                        'total_value' => Money::multiply($allocation['quantity'], (string) $allocation['item']->unit_price),
                        'transaction_date' => $dispatchedAt,
                    ]);
                }

                $order->update(['status' => OrderStatus::Dispatched, 'dispatched_at' => $dispatchedAt]);
                $this->audit($order, 'orders.status_changed', OrderStatus::Confirmed, OrderStatus::Dispatched, $user, [
                    'allocations' => array_map(fn (array $allocation): array => [
                        'product_id' => $allocation['item']->product_id,
                        'warehouse_stock_id' => $allocation['stock']->id,
                        'quantity' => $allocation['quantity'],
                    ], $allocations),
                ]);

                return $order->refresh()->load('items.product');
            });
        } catch (OrderStockShortageException $exception) {
            $this->notifications->orderDispatchBlocked($order, $exception->shortages, $user);

            throw $exception;
        }
    }

    public function deliver(Order $order, User $user): Order
    {
        return $this->transition($order, OrderStatus::Delivered, 'delivered_at', $user);
    }

    public function cancel(Order $order, User $user): Order
    {
        return $this->transition($order, OrderStatus::Cancelled, 'cancelled_at', $user);
    }

    /**
     * Plans the whole dispatch before touching stock, so a short line aborts with nothing deducted.
     *
     * @param  iterable<OrderItem>  $items
     * @return array<int, array{item: OrderItem, stock: WarehouseStock, quantity: int}>
     */
    private function allocateFifo(iterable $items): array
    {
        $allocations = [];
        $shortages = [];

        foreach ($items as $item) {
            $lines = WarehouseStock::query()
                ->select('warehouse_stock.*')
                ->join('production_batches', 'production_batches.id', '=', 'warehouse_stock.batch_id')
                ->where('warehouse_stock.product_id', $item->product_id)
                ->where('warehouse_stock.current_level', '>', 0)
                ->orderBy('production_batches.batch_date')
                ->orderBy('warehouse_stock.id')
                ->lockForUpdate()
                ->get();
            $remaining = $item->quantity;

            foreach ($lines as $line) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($remaining, (int) $line->current_level);
                $allocations[] = ['item' => $item, 'stock' => $line, 'quantity' => $take];
                $remaining -= $take;
            }

            if ($remaining > 0) {
                $shortages[$item->id] = "{$item->product->unit_type}: need ".number_format($item->quantity)
                    .', only '.number_format($item->quantity - $remaining).' in stock.';
            }
        }

        if ($shortages !== []) {
            throw new OrderStockShortageException($shortages, 'dispatch');
        }

        return $allocations;
    }

    private function transition(Order $order, OrderStatus $to, string $timestampColumn, User $user): Order
    {
        return DB::transaction(function () use ($order, $to, $timestampColumn, $user): Order {
            $order = $this->lock($order);
            $from = $order->status;
            $this->assertTransition($order, $to);
            $order->update(['status' => $to, $timestampColumn => now()]);
            $this->audit($order, 'orders.status_changed', $from, $to, $user);

            return $order->refresh()->load('items.product');
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->with('customer')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertTransition(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw new InvalidOrderTransitionException($order->status, $to);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Order $order, string $action, ?OrderStatus $from, OrderStatus $to, User $user, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'table_name' => 'orders',
            'old_values' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $from?->value],
            'new_values' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $to->value, ...$details],
        ]);
    }
}
