<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
