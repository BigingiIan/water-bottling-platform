<?php

namespace App\Livewire;

use App\Exceptions\Orders\OrderStockShortageException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\Money;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\StockAvailability;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Order', 'breadcrumbSection' => 'Orders', 'activeItem' => 'Orders'])]
class OrderEditor extends Component
{
    #[Locked]
    public ?int $orderId = null;

    public string $customerId = '';

    public string $requestedDate = '';

    public string $notes = '';

    /**
     * @var array<int, array{product_id: string, quantity: string}>
     */
    public array $lines = [];

    /**
     * Shortage messages from the last confirm attempt, keyed by product id.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $shortages = [];

    public bool $customerModalOpen = false;

    public string $customerName = '';

    public string $customerContact = '';

    public string $customerAddress = '';

    public string $customerType = '';

    public string $customerPaymentTerms = '30';

    public function mount(?Order $order = null): void
    {
        $this->authorizeWrite();

        if ($order?->exists) {
            if ($order->status !== OrderStatus::Draft) {
                $this->redirectRoute('orders.show', $order);

                return;
            }

            $this->orderId = $order->id;
            $this->customerId = (string) $order->customer_id;
            $this->requestedDate = $order->requested_date?->toDateString() ?? '';
            $this->notes = (string) $order->notes;
            $this->lines = $order->items()->orderBy('id')->get()
                ->map(fn ($item): array => ['product_id' => (string) $item->product_id, 'quantity' => (string) $item->quantity])
                ->all();
        }

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => '', 'quantity' => '1'];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function saveDraft(OrderService $orders): void
    {
        $order = $this->persistDraft($orders);
        session()->flash('status', "{$order->order_number} saved as a draft.");
        $this->redirectRoute('orders.show', $order);
    }

    public function confirmOrder(OrderService $orders): void
    {
        $order = $this->persistDraft($orders);

        try {
            $orders->confirm($order, $this->authorizeWrite());
        } catch (OrderStockShortageException $exception) {
            $this->shortages = $order->items()->get()
                ->filter(fn ($item): bool => array_key_exists($item->id, $exception->shortages))
                ->mapWithKeys(fn ($item): array => [$item->product_id => $exception->shortages[$item->id]])
                ->all();
            $this->addError('confirm', 'This order cannot be confirmed yet. It has been saved as a draft.');

            return;
        }

        session()->flash('status', "{$order->order_number} confirmed.");
        $this->redirectRoute('orders.show', $order);
    }

    public function openCustomerModal(): void
    {
        $this->authorizeWrite();
        $this->reset(['customerName', 'customerContact', 'customerAddress', 'customerType']);
        $this->customerPaymentTerms = '30';
        $this->resetValidation();
        $this->customerModalOpen = true;
    }

    public function closeCustomerModal(): void
    {
        $this->customerModalOpen = false;
        $this->resetValidation();
    }

    public function saveCustomer(): void
    {
        $this->authorizeWrite();
        $validated = $this->validate(OrdersPage::customerRules());

        $customer = Customer::query()->create([
            'name' => trim($validated['customerName']),
            'contact_number' => $validated['customerContact'] ?: null,
            'address' => $validated['customerAddress'] ?: null,
            'customer_type' => $validated['customerType'] ?: null,
            'payment_terms_days' => (int) $validated['customerPaymentTerms'],
        ]);

        $this->customerId = (string) $customer->id;
        $this->customerModalOpen = false;
    }

    public function render(StockAvailability $availability): View
    {
        $this->authorizeWrite();
        $products = Product::query()->orderBy('unit_type')->get(['id', 'name', 'unit_type', 'unit_price_kes']);
        $productsById = $products->keyBy('id');
        $lineTotals = [];

        foreach ($this->lines as $index => $line) {
            $product = $productsById->get((int) $line['product_id']);
            $quantity = ctype_digit((string) $line['quantity']) ? (int) $line['quantity'] : 0;
            $lineTotals[$index] = $product?->unit_price_kes === null ? null : Money::multiply($quantity, (string) $product->unit_price_kes);
        }

        return view('livewire.order-editor', [
            'order' => $this->orderId === null ? null : Order::query()->find($this->orderId),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'products' => $products,
            'productsById' => $productsById,
            'availability' => $availability->forProducts($products->pluck('id')->all(), $this->orderId),
            'lineTotals' => $lineTotals,
            'orderTotal' => Money::sum(array_values(array_filter($lineTotals, fn (?string $total): bool => $total !== null))),
        ]);
    }

    private function persistDraft(OrderService $orders): Order
    {
        $user = $this->authorizeWrite();
        $this->shortages = [];
        $validated = $this->validate([
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')],
            'requestedDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ], [
            'lines.*.product_id.distinct' => 'Each product can appear on one line only.',
            'lines.*.product_id.required' => 'Choose a product.',
            'lines.*.quantity.min' => 'Quantity must be at least 1.',
        ]);

        $quantities = [];

        foreach ($validated['lines'] as $line) {
            $quantities[(int) $line['product_id']] = (int) $line['quantity'];
        }

        $order = $orders->saveDraft(
            $this->orderId === null ? null : Order::query()->findOrFail($this->orderId),
            Customer::query()->findOrFail((int) $validated['customerId']),
            $quantities,
            $validated['requestedDate'] ?: null,
            $validated['notes'] ?: null,
            $user,
        );
        $this->orderId = $order->id;

        return $order;
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'orders', write: true);

        return $user;
    }
}
