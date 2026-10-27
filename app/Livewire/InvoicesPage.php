<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Invoicing\InvoiceStatus;
use App\Services\Orders\Money;
use App\Services\UserPreferenceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Invoices', 'breadcrumbSection' => 'Finance', 'activeItem' => 'Invoices'])]
class InvoicesPage extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $customerFilter = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    #[Url(except: false)]
    public bool $overdueOnly = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['statusFilter', 'customerFilter', 'dateFrom', 'dateTo', 'overdueOnly'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'invoicing');

        $invoices = $this->filteredInvoices()
            ->with('customer:id,name')
            ->orderByRaw('issue_date IS NULL DESC')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(app(UserPreferenceService::class)->pageSize($user, 15));

        return view('livewire.invoices-page', [
            'invoices' => $invoices,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => InvoiceStatus::cases(),
            'kpis' => $this->kpis(),
        ]);
    }

    /**
     * @return array{outstanding: string, overdueCount: int, overdueAmount: string, paidThisMonth: string}
     */
    private function kpis(): array
    {
        $balances = fn (Builder $query): string => Money::sum(
            $query->get(['total', 'amount_paid'])->map(fn (Invoice $invoice): string => $invoice->balance())->all(),
        );
        $overdue = Invoice::query()->overdue();

        return [
            'outstanding' => $balances(Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])),
            'overdueCount' => (clone $overdue)->count(),
            'overdueAmount' => $balances(clone $overdue),
            'paidThisMonth' => Money::sum(
                Payment::query()->live()->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->pluck('amount')->map(fn (mixed $amount): string => (string) $amount)->all(),
            ),
        ];
    }

    private function filteredInvoices(): Builder
    {
        return Invoice::query()
            ->when(in_array($this->statusFilter, array_column(InvoiceStatus::cases(), 'value'), true), fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->when(ctype_digit($this->customerFilter), fn (Builder $query): Builder => $query->where('customer_id', (int) $this->customerFilter))
            ->when($this->isDate($this->dateFrom), fn (Builder $query): Builder => $query->whereDate('issue_date', '>=', $this->dateFrom))
            ->when($this->isDate($this->dateTo), fn (Builder $query): Builder => $query->whereDate('issue_date', '<=', $this->dateTo))
            ->when($this->overdueOnly, fn (Builder $query): Builder => $query->overdue());
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
