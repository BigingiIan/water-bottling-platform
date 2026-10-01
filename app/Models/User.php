<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role_id',
        'status',
        'last_active_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
