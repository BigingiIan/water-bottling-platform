<?php

namespace App\Services\Invoicing;

use App\Support\YearlySequence;

class InvoiceNumberGenerator
{
    public function __construct(private YearlySequence $sequence) {}

    /**
     * Reserves the next invoice number for the year; call inside the transaction that issues the invoice.
     */
    public function next(int $year): string
    {
        return $this->sequence->next('invoice_number_sequences', 'INV', $year);
    }
}
