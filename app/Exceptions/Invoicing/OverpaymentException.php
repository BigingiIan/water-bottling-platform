<?php

namespace App\Exceptions\Invoicing;

use App\Services\Orders\Money;

class OverpaymentException extends InvoicingException
{
    public function __construct(string $amount, string $balance)
    {
        parent::__construct('A payment of '.Money::format($amount).' is more than the outstanding balance of '.Money::format($balance).'.');
    }
}
