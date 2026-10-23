<?php

namespace App\Exceptions\Orders;

use App\Services\Orders\OrderStatus;
use DomainException;

class InvalidOrderTransitionException extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        $reason = $from === OrderStatus::Dispatched && $to === OrderStatus::Cancelled
            ? ' Dispatched orders cannot be cancelled; record a return instead.'
            : '';

        parent::__construct("A {$from->value} order cannot be moved to {$to->value}.{$reason}");
    }
}
