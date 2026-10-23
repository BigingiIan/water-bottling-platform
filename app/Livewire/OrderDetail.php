<?php

namespace App\Livewire;

use App\Exceptions\Invoicing\InvoicingException;
use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Exceptions\Orders\OrderStockShortageException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatus;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Order', 'breadcrumbSection' => 'Orders', 'activeItem' => 'Orders'])]
class OrderDetail extends Component
{
    #[Locked]
    public int $orderId;

    /**
     * @var array<int, string>
     */
    #[Locked]
    public array $problems = [];

    public function mount(Order $order): void
    {
        app(ModuleAccess::class)->authorize($this->user(), 'orders');
        $this->orderId = $order->id;
    }

    public function confirm(OrderService $orders): void
    {
        $this->run(fn (Order $order, User $user) => $orders->confirm($order, $user), 'Order confirmed.');
    }

    public function dispatchOrder(OrderService $orders): void
    {
        $this->run(fn (Order $order, User $user) => $orders->dispatch($order, $user), 'Order dispatched. Stock has been deducted.');
    }

    public function deliver(OrderService $orders): void
    {
        $this->run(fn (Order $order, User $user) => $orders->deliver($order, $user), 'Order marked as delivered.');
    }

    public function cancel(OrderService $orders): void
    {
        $this->run(fn (Order $order, User $user) => $orders->cancel($order, $user), 'Order cancelled.');
    }

    /**
     * Creates a draft invoice from this dispatched or delivered order and opens it.
     */
    public function createInvoice(InvoiceService $invoices): void
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'invoicing', write: true);
        $this->problems = [];

        try {
            $invoice = $invoices->createDraftFromOrder(Order::query()->findOrFail($this->orderId), $user);
        } catch (InvoicingException $exception) {
            $this->problems = [$exception->getMessage()];

            return;
        }

        session()->flash('status', 'Draft invoice created. Check it, then issue it.');
        $this->redirectRoute('invoices.show', $invoice);
    }

    public function render(): View
    {
        $user = $this->user();
        $access = app(ModuleAccess::class);
        $access->authorize($user, 'orders');
        $order = Order::query()->with(['customer', 'creator:id,name', 'items.product'])->findOrFail($this->orderId);

        return view('livewire.order-detail', [
            'order' => $order,
            'canWrite' => $access->can($user, 'orders', write: true),
            'nextStatuses' => $order->status->nextStatuses(),
            'canViewInvoices' => $access->can($user, 'invoicing'),
            'canCreateInvoice' => $access->can($user, 'invoicing', write: true)
                && in_array($order->status, [OrderStatus::Dispatched, OrderStatus::Delivered], true),
            'liveInvoice' => $order->liveInvoice()->first(),
            'timeline' => AuditLog::query()
                ->with('user:id,name')
                ->where('table_name', 'orders')
                ->where('new_values->order_id', $order->id)
                ->orderBy('timestamp')
                ->orderBy('id')
                ->get(),
            'allocations' => in_array($order->status, [OrderStatus::Dispatched, OrderStatus::Delivered], true)
                ? StockMovement::query()
                    ->with(['product:id,unit_type', 'stock:id,batch_id,warehouse_location', 'stock.batch:id,batch_date'])
                    ->where('reference', $order->order_number)
                    ->where('type', 'sale')
                    ->orderBy('id')
                    ->get()
                : collect(),
        ]);
    }

    private function run(callable $action, string $message): void
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'orders', write: true);
        $this->problems = [];

        try {
            $action(Order::query()->findOrFail($this->orderId), $user);
        } catch (OrderStockShortageException $exception) {
            $this->problems = array_values($exception->shortages);

            return;
        } catch (InvalidOrderTransitionException $exception) {
            $this->problems = [$exception->getMessage()];

            return;
        }

        $this->dispatch('orders-toast', message: $message);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
