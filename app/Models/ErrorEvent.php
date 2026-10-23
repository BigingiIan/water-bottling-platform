<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reference',
        'user_id',
        'status_code',
        'module_slug',
        'path',
        'method',
        'exception_class',
        'message',
        'exception_message',
        'exception_file',
        'exception_line',
        'trace',
        'fingerprint',
        'role_slug',
        'ip_address',
        'resolved_at',
        'resolution_note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'resolved_at' => 'datetime',
            'status_code' => 'integer',
            'exception_line' => 'integer',
            'trace' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
