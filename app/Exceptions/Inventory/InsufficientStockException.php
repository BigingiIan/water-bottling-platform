<?php

namespace App\Exceptions\Inventory;

use DomainException;

class InsufficientStockException extends DomainException
{
    public function __construct()
    {
        parent::__construct('The stock movement would reduce the stock level below zero.');
    }
}
