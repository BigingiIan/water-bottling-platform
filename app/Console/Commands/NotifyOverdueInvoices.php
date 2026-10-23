<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:notify-overdue')]
#[Description('Notify invoicing users about invoices past their due date (once per invoice while unread)')]
class NotifyOverdueInvoices extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $overdue = Invoice::query()->overdue()->with('customer:id,name')->orderBy('due_date')->get();

        foreach ($overdue as $invoice) {
            $notifications->invoiceOverdue($invoice);
        }

        $this->info("Checked {$overdue->count()} overdue ".str('invoice')->plural($overdue->count()).'.');

        return self::SUCCESS;
    }
}
