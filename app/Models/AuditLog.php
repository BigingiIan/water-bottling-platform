<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'user_id',
        'target_user_id',
        'action',
        'table_name',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    const CREATED_AT = 'timestamp';

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
