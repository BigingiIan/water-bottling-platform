<?php

namespace App\Models;

use App\Services\Requests\RequestPriority;
use App\Services\Requests\RequestStatus;
use App\Services\Requests\RequestType;
use Database\Factories\DepartmentRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request from one user to another department. Named DepartmentRequest because "Request" is the HTTP request.
 */
class DepartmentRequest extends Model
{
    /** @use HasFactory<DepartmentRequestFactory> */
    use HasFactory;

    protected $table = 'requests';

    /**
     * Status, resolution and reopen fields change only through RequestService.
     */
    protected $fillable = [
        'reference',
        'type',
        'subject',
        'body',
        'priority',
        'from_user_id',
        'to_role_id',
        'assigned_to_user_id',
        'due_date',
        'related_type',
        'related_id',
        'context_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'priority' => RequestPriority::class,
            'status' => RequestStatus::class,
            'due_date' => 'date',
            'context_snapshot' => 'array',
            'resolved_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'to_role_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RequestComment::class, 'request_id');
    }

    /**
     * Requests the user sent or that are addressed to their role; administrators see every request.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role?->slug === 'administrator') {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('from_user_id', $user->id)
            ->when($user->role_id !== null, fn (Builder $query) => $query->orWhere('to_role_id', $user->role_id)));
    }

    /**
     * Active requests addressed to the user's department or assigned to them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpenFor(Builder $query, User $user): Builder
    {
        return $query
            ->whereIn('status', RequestStatus::activeValues())
            ->where(fn (Builder $query) => $query
                ->where('assigned_to_user_id', $user->id)
                ->when($user->role_id !== null, fn (Builder $query) => $query->orWhere('to_role_id', $user->role_id)));
    }

    /**
     * Requests to the user's department that nobody has picked up yet, excluding the user's own.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query
            ->where('status', RequestStatus::Open->value)
            ->where('to_role_id', $user->role_id)
            ->where('from_user_id', '!=', $user->id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query, ?Carbon $today = null): Builder
    {
        return $query
            ->whereIn('status', RequestStatus::activeValues())
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', ($today ?? today())->toDateString());
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status->isActive()
            && $this->due_date !== null
            && ($today ?? today())->greaterThan($this->due_date);
    }

    public function isAddressedTo(User $user): bool
    {
        return $user->role_id !== null && $this->to_role_id === $user->role_id;
    }

    public function url(): string
    {
        return route('requests.show', $this);
    }
}
