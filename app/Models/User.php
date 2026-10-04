<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            $wasActiveAdministrator = $user->getRawOriginal('status') === 'active'
                && DB::table('roles')
                    ->where('id', $user->getRawOriginal('role_id'))
                    ->where('slug', 'administrator')
                    ->exists();

            if (! $wasActiveAdministrator) {
                return;
            }

            $willRemainActiveAdministrator = $user->status === 'active'
                && DB::table('roles')
                    ->where('id', $user->role_id)
                    ->where('slug', 'administrator')
                    ->exists();

            if ($willRemainActiveAdministrator) {
                return;
            }

            $otherActiveAdministrators = static::query()
                ->where('users.id', '!=', $user->getKey())
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.slug', 'administrator')
                ->where('users.status', 'active')
                ->count();

            if ($otherActiveAdministrators === 0) {
                $attribute = $user->status !== 'active' ? 'status' : 'role_id';

                throw ValidationException::withMessages([
                    $attribute => 'At least one active Administrator must remain.',
                ]);
            }
        });
    }

    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role_id',
        'status',
        'must_change_password',
        'last_active_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'must_change_password' => 'boolean',
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
