<?php

namespace App\Exceptions\Inventory;

use DomainException;

class NonIntegerStockLevelException extends DomainException
{
    public function __construct()
    {
        parent::__construct('The resulting stock level must be an integer to record a stock movement.');
    }
}
