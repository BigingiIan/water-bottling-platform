<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'contact_number',
        'address',
        'customer_type',
    ];

    public function salesTransactions(): HasMany
    {
        return $this->hasMany(SalesTransaction::class);
    }
}
