<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\SettingsService;

/**
 * View data for the invoice document, shared by the detail page and the PDF.
 */
class InvoiceDocument
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{invoice: Invoice, organisation: array{name: string, address: string, phone: string, kraPin: string}, footerText: string}
     */
    public function viewData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice->loadMissing(['items', 'customer', 'order']),
            'organisation' => [
                'name' => (string) $this->settings->get('organisation.name', config('app.name')),
                'address' => (string) $this->settings->get('organisation.address', ''),
                'phone' => (string) $this->settings->get('organisation.phone', ''),
                'kraPin' => (string) $this->settings->get('organisation.kra_pin', ''),
            ],
            'footerText' => (string) $this->settings->get('invoice.footer_text', ''),
        ];
    }

    public function filename(Invoice $invoice): string
    {
        return ($invoice->invoice_number ?? 'draft-invoice-'.$invoice->id).'.pdf';
    }
}
