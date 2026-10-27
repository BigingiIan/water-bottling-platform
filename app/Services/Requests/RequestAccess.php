<?php

namespace App\Services\Requests;

use App\Models\DepartmentRequest;
use App\Models\User;
use App\Support\ModuleAccess;

/**
 * Who may see and act on a request. Administrators see every thread; everyone else sees only requests
 * they sent or that are addressed to their role, and only the receiving department changes the status.
 */
class RequestAccess
{
    public function __construct(private ModuleAccess $moduleAccess) {}

    public function canUseRequests(User $user): bool
    {
        return $user->status !== 'suspended' && $this->moduleAccess->can($user, 'requests');
    }

    public function canSendRequests(User $user): bool
    {
        return $user->status !== 'suspended' && $this->moduleAccess->can($user, 'requests', write: true);
    }

    public function isAdministrator(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }

    public function canView(User $user, DepartmentRequest $request): bool
    {
        return $this->canUseRequests($user)
            && ($this->isAdministrator($user) || $request->from_user_id === $user->id || $request->isAddressedTo($user));
    }

    /**
     * The receiving department, or an administrator, moves the request through its statuses.
     */
    public function canManage(User $user, DepartmentRequest $request): bool
    {
        return $this->canSendRequests($user)
            && ($this->isAdministrator($user) || $request->isAddressedTo($user));
    }

    public function canComment(User $user, DepartmentRequest $request): bool
    {
        return $this->canSendRequests($user) && $this->canView($user, $request);
    }

    public function canCancel(User $user, DepartmentRequest $request): bool
    {
        return $this->canSendRequests($user)
            && $request->from_user_id === $user->id
            && $request->status === RequestStatus::Open;
    }

    /**
     * A sender may reopen a declined request once.
     */
    public function canReopen(User $user, DepartmentRequest $request): bool
    {
        return $this->canSendRequests($user)
            && $request->from_user_id === $user->id
            && $request->status === RequestStatus::Declined
            && $request->reopened_at === null;
    }

    /**
     * Aborts with 404 rather than 403 so a request's existence is not revealed to other departments.
     */
    public function authorizeView(User $user, DepartmentRequest $request): void
    {
        abort_unless($this->canView($user, $request), 404);
    }
}
