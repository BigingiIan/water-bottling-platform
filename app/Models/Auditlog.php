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
        'action',
        'table_name',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    const CREATED_AT = 'timestamp';
    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
