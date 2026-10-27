<?php

namespace App\Exceptions\Orders;

use DomainException;

class OrderStockShortageException extends DomainException
{
    /**
     * @param  array<int, string>  $shortages  per-line messages keyed by order item id
     */
    public function __construct(public readonly array $shortages, string $action = 'confirm')
    {
        parent::__construct("Not enough stock to {$action} this order: ".implode(' ', $shortages));
    }
}
