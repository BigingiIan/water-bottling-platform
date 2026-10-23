<?php

namespace App\Services\Orders;

use App\Support\YearlySequence;

class OrderNumberGenerator
{
    public function __construct(private YearlySequence $sequence) {}

    /**
     * Reserves the next order number for the year; call inside the transaction that inserts the order.
     */
    public function next(int $year): string
    {
        return $this->sequence->next('order_number_sequences', 'ORD', $year);
    }
}
