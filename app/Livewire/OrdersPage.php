<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Money;
use App\Services\Orders\OrderStatus;
use App\Services\UserPreferenceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Orders', 'breadcrumbSection' => 'Inventory', 'activeItem' => 'Orders'])]
class OrdersPage extends Component
{
    use WithPagination;

    private const TABS = ['orders', 'customers'];

    #[Url(as: 'tab', except: 'orders')]
    public string $activeTab = 'orders';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $customerFilter = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    public bool $customerModalOpen = false;

    #[Locked]
    public ?int $editingCustomerId = null;

    public string $customerName = '';

    public string $customerContact = '';

    public string $customerAddress = '';

    public string $customerType = '';

    public string $customerPaymentTerms = '30';

    public function mount(): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'orders';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['statusFilter', 'customerFilter', 'dateFrom', 'dateTo'], true)) {
            $this->validateOnly($property, $this->filterRules());
            $this->resetPage();
        }

        if ($property === 'activeTab' && ! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'orders';
        }
    }

    public function openCustomerModal(?int $customerId = null): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $customer = $customerId === null ? null : Customer::query()->findOrFail($customerId);

        $this->editingCustomerId = $customer?->id;
        $this->customerName = $customer->name ?? '';
        $this->customerContact = $customer->contact_number ?? '';
        $this->customerAddress = $customer->address ?? '';
        $this->customerType = $customer->customer_type ?? '';
        $this->customerPaymentTerms = (string) ($customer->payment_terms_days ?? 30);
        $this->customerModalOpen = true;
    }

    public function closeCustomerModal(): void
    {
        $this->customerModalOpen = false;
        $this->editingCustomerId = null;
        $this->resetValidation();
    }

    public function saveCustomer(): void
    {
        $this->authorizeWrite();
        $validated = $this->validate(self::customerRules());

        $attributes = [
            'name' => trim($validated['customerName']),
            'contact_number' => $validated['customerContact'] ?: null,
            'address' => $validated['customerAddress'] ?: null,
            'customer_type' => $validated['customerType'] ?: null,
            'payment_terms_days' => (int) $validated['customerPaymentTerms'],
        ];

        if ($this->editingCustomerId === null) {
            Customer::query()->create($attributes);
        } else {
            Customer::query()->findOrFail($this->editingCustomerId)->update($attributes);
        }

        $message = $this->editingCustomerId === null ? 'Customer added.' : 'Customer updated.';
        $this->closeCustomerModal();
        $this->dispatch('orders-toast', message: $message);
    }

    /**
     * Shared with the order editor's quick-add customer form.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function customerRules(): array
    {
        return [
            'customerName' => ['required', 'string', 'max:255'],
            'customerContact' => ['nullable', 'string', 'max:40'],
            'customerAddress' => ['nullable', 'string', 'max:255'],
            'customerType' => ['nullable', 'string', 'max:60'],
            'customerPaymentTerms' => ['required', 'integer', 'between:0,365'],
        ];
    }

    public function render(): View
    {
        $user = $this->user();
        $access = app(ModuleAccess::class);
        $access->authorize($user, 'orders');
        $pageSize = app(UserPreferenceService::class)->pageSize($user, 15);

        return view('livewire.orders-page', [
            'canWrite' => $access->can($user, 'orders', write: true),
            'statuses' => OrderStatus::cases(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'kpis' => $this->activeTab === 'orders' ? $this->kpis() : null,
            'orders' => $this->activeTab === 'orders'
                ? $this->filteredOrders()
                    ->with(['customer:id,name', 'items.product:id,unit_price_kes'])
                    ->withCount('items')
                    ->orderByDesc('order_date')
                    ->orderByDesc('id')
                    ->paginate($pageSize, pageName: 'ordersPage')
                : null,
            'customerRows' => $this->activeTab === 'customers'
                ? Customer::query()->withCount('orders')->orderBy('name')->paginate($pageSize, pageName: 'customersPage')
                : null,
        ]);
    }

    /**
     * @return array{open: int, toDispatchToday: int, dispatchedToday: int, valueThisMonth: string}
     */
    private function kpis(): array
    {
        $monthTotals = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', [OrderStatus::Dispatched->value, OrderStatus::Delivered->value])
            ->whereBetween('orders.dispatched_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->pluck('order_items.line_total')
            ->map(fn (mixed $total): string => (string) $total)
            ->all();

        return [
            'open' => Order::query()->whereIn('status', [OrderStatus::Draft->value, OrderStatus::Confirmed->value])->count(),
            'toDispatchToday' => Order::query()
                ->where('status', OrderStatus::Confirmed->value)
                ->where(fn (Builder $query): Builder => $query->whereNull('requested_date')->orWhereDate('requested_date', '<=', today()))
                ->count(),
            'dispatchedToday' => Order::query()->whereNotNull('dispatched_at')->whereDate('dispatched_at', today())->count(),
            'valueThisMonth' => Money::sum($monthTotals),
        ];
    }

    private function filteredOrders(): Builder
    {
        return Order::query()
            ->when(in_array($this->statusFilter, array_column(OrderStatus::cases(), 'value'), true), fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->when(ctype_digit($this->customerFilter), fn (Builder $query): Builder => $query->where('customer_id', (int) $this->customerFilter))
            ->when($this->isDate($this->dateFrom), fn (Builder $query): Builder => $query->whereDate('order_date', '>=', $this->dateFrom))
            ->when($this->isDate($this->dateTo), fn (Builder $query): Builder => $query->whereDate('order_date', '<=', $this->dateTo));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(): array
    {
        return [
            'statusFilter' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'customerFilter' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function authorizeWrite(): User
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'orders', write: true);

        return $user;
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
