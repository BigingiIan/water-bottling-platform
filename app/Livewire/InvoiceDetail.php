<?php

namespace App\Livewire;

use App\Exceptions\Invoicing\InvoicingException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Invoicing\InvoiceDocument;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\InvoiceStatus;
use App\Services\Invoicing\PaymentService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Invoice', 'breadcrumbSection' => 'Finance', 'activeItem' => 'Invoices'])]
class InvoiceDetail extends Component
{
    #[Locked]
    public int $invoiceId;

    public bool $paymentModalOpen = false;

    public string $paymentAmount = '';

    public string $paymentMethod = 'mpesa';

    public string $paymentReference = '';

    public string $paymentDate = '';

    public string $paymentNotes = '';

    public bool $voidModalOpen = false;

    public string $voidReason = '';

    #[Locked]
    public ?int $reversingPaymentId = null;

    public string $reversalReason = '';

    public function mount(Invoice $invoice): void
    {
        app(ModuleAccess::class)->authorize($this->user(), 'invoicing');
        $this->invoiceId = $invoice->id;
    }

    public function issue(InvoiceService $invoices): void
    {
        $this->attempt(fn (User $user) => $invoices->issue($this->invoice(), $user), 'Invoice issued.');
    }

    public function discard(InvoiceService $invoices): void
    {
        $user = $this->authorizeWrite();

        try {
            $invoices->discardDraft($this->invoice(), $user);
        } catch (InvoicingException $exception) {
            $this->addError('invoiceAction', $exception->getMessage());

            return;
        }

        session()->flash('status', 'Draft invoice discarded.');
        $this->redirectRoute('invoices.index');
    }

    public function openPaymentModal(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $this->paymentAmount = $this->invoice()->balance();
        $this->paymentMethod = 'mpesa';
        $this->paymentReference = '';
        $this->paymentDate = today()->toDateString();
        $this->paymentNotes = '';
        $this->paymentModalOpen = true;
    }

    public function closePaymentModal(): void
    {
        $this->paymentModalOpen = false;
        $this->resetValidation();
    }

    public function savePayment(PaymentService $payments): void
    {
        $user = $this->authorizeWrite();
        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            'paymentMethod' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paymentReference' => [Rule::requiredIf($this->paymentMethod === 'mpesa'), 'nullable', 'string', 'max:64'],
            'paymentDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paymentNotes' => ['nullable', 'string', 'max:500'],
        ], ['paymentReference.required' => 'Enter the M-Pesa transaction code.'], [
            'paymentAmount' => 'amount',
            'paymentMethod' => 'method',
            'paymentReference' => 'reference',
            'paymentDate' => 'payment date',
        ]);

        try {
            $payments->record(
                $this->invoice(),
                (string) $validated['paymentAmount'],
                $validated['paymentMethod'],
                $validated['paymentReference'] ?: null,
                Carbon::parse($validated['paymentDate'])->setTimeFrom(now()),
                $validated['paymentNotes'] ?: null,
                $user,
            );
        } catch (InvoicingException $exception) {
            $this->addError('paymentAmount', $exception->getMessage());

            return;
        }

        $this->paymentModalOpen = false;
        $this->dispatch('invoice-toast', message: 'Payment recorded.');
    }

    public function openVoidModal(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $this->voidReason = '';
        $this->voidModalOpen = true;
    }

    public function closeVoidModal(): void
    {
        $this->voidModalOpen = false;
        $this->resetValidation();
    }

    public function saveVoid(InvoiceService $invoices): void
    {
        $this->authorizeWrite();
        $this->validate(['voidReason' => ['required', 'string', 'max:1000']], [], ['voidReason' => 'reason']);

        if ($this->attempt(fn (User $user) => $invoices->void($this->invoice(), $this->voidReason, $user), 'Invoice voided. Create a new invoice from the order if it needs correcting.')) {
            $this->voidModalOpen = false;
        }
    }

    public function openReversalModal(int $paymentId): void
    {
        $this->authorizeWrite();
        $payment = $this->invoice()->payments()->live()->findOrFail($paymentId);
        $this->resetValidation();
        $this->reversingPaymentId = $payment->id;
        $this->reversalReason = '';
    }

    public function closeReversalModal(): void
    {
        $this->reversingPaymentId = null;
        $this->resetValidation();
    }

    public function saveReversal(PaymentService $payments): void
    {
        $this->authorizeWrite();
        $this->validate(['reversalReason' => ['required', 'string', 'max:1000']], [], ['reversalReason' => 'reason']);
        $payment = $this->invoice()->payments()->findOrFail((int) $this->reversingPaymentId);

        if ($this->attempt(fn (User $user) => $payments->reverse($payment, $this->reversalReason, $user), 'Payment reversed.')) {
            $this->reversingPaymentId = null;
        }
    }

    public function render(InvoiceDocument $document): View
    {
        $user = $this->user();
        $access = app(ModuleAccess::class);
        $access->authorize($user, 'invoicing');
        $invoice = Invoice::query()->with(['items', 'customer', 'order', 'issuer:id,name', 'voider:id,name'])->findOrFail($this->invoiceId);

        return view('livewire.invoice-detail', [
            ...$document->viewData($invoice),
            'canWrite' => $access->can($user, 'invoicing', write: true),
            'payments' => $invoice->payments()->with(['recorder:id,name', 'reverser:id,name'])->orderBy('paid_at')->orderBy('id')->get(),
            'reversingPayment' => $this->reversingPaymentId === null ? null : $invoice->payments()->find($this->reversingPaymentId),
            'methods' => Payment::METHODS,
            'replacement' => $invoice->status === InvoiceStatus::Void
                ? Invoice::query()->where('order_id', $invoice->order_id)->where('id', '!=', $invoice->id)->where('status', '!=', InvoiceStatus::Void->value)->first()
                : null,
            'history' => AuditLog::query()->with('user:id,name')
                ->where(fn ($query) => $query->where('table_name', 'invoices')->orWhere('table_name', 'payments'))
                ->where('new_values->invoice_id', $invoice->id)
                ->orderBy('timestamp')->orderBy('id')->get(),
        ]);
    }

    /**
     * Runs a write action, turning rule violations into a message instead of an error page.
     */
    private function attempt(callable $action, string $success): bool
    {
        $user = $this->authorizeWrite();

        try {
            $action($user);
        } catch (InvoicingException $exception) {
            $this->addError('invoiceAction', $exception->getMessage());

            return false;
        }

        $this->dispatch('invoice-toast', message: $success);

        return true;
    }

    private function invoice(): Invoice
    {
        return Invoice::query()->findOrFail($this->invoiceId);
    }

    private function authorizeWrite(): User
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'invoicing', write: true);

        return $user;
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
