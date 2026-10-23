<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Role extends Model
{
    private const SYSTEM_ROLE_SLUGS = [
        'administrator',
        'production-manager',
        'production-staff',
        'inventory-staff',
        'maintenance-staff',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            if (
                $role->getOriginal('slug') === 'administrator'
                && ($role->isDirty('name') || $role->isDirty('slug'))
            ) {
                throw ValidationException::withMessages([
                    'name' => 'The Administrator role cannot be renamed.',
                ]);
            }
        });

        static::deleting(function (Role $role): void {
            if ($role->isSystemRole()) {
                throw ValidationException::withMessages([
                    'roleAction' => "'{$role->name}' is a system role and cannot be deleted.",
                ]);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'role_module_permissions')
            ->withPivot('access_level');
    }

    public function isSystemRole(): bool
    {
        return in_array($this->slug, self::SYSTEM_ROLE_SLUGS, true);
    }
}
