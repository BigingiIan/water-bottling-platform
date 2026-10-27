<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in a request thread. Comments are append-only.
 */
class RequestComment extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'user_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Request comments cannot be edited.'));
        static::deleting(fn (): never => throw new LogicException('Request comments cannot be deleted.'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DepartmentRequest::class, 'request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
