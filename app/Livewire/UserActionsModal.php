<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class UserActionsModal extends Component
{
    public bool $isOpen = false;

    public string $action = '';

    public ?int $targetUserId = null;

    public ?int $roleId = null;

    public ?string $temporaryPassword = null;

    #[On('open-role-modal')]
    public function openRoleModal(int $userId): void
    {
        $this->openFor($userId, 'role');

        $target = User::query()->find($this->targetUserId);
        $this->roleId = $target?->role_id;
    }

    #[On('open-suspend-modal')]
    public function openSuspendModal(int $userId): void
    {
        $this->openFor($userId, 'suspend');
    }

    #[On('open-reset-modal')]
    public function openResetModal(int $userId): void
    {
        $this->openFor($userId, 'reset');
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset(['action', 'targetUserId', 'roleId', 'temporaryPassword']);
    }

    public function saveRole(): void
    {
        $actor = $this->authorizeWrite();

        $validated = $this->validate([
            'targetUserId' => ['required', 'integer', Rule::exists('users', 'id')],
            'roleId' => ['required', 'integer', Rule::exists('roles', 'id')],
        ]);

        $target = User::query()->findOrFail($validated['targetUserId']);
        $oldRole = $target->role?->slug ?? 'none';

        if ($target->role_id === $validated['roleId']) {
            $this->closeModal();

            return;
        }

        if ($this->isLastActiveAdministrator($target)) {
            $this->addError('roleId', 'The last active Administrator cannot have their role changed.');

            return;
        }

        $target->update(['role_id' => $validated['roleId']]);

        $this->audit($actor, $target, 'user.role_changed', ['role' => $oldRole], ['role' => $target->fresh()->role?->slug]);
        $this->dispatch('user-changed', userId: $target->id);
        $this->closeModal();
    }

    public function confirmSuspend(): void
    {
        $actor = $this->authorizeWrite();

        $validated = $this->validate([
            'targetUserId' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $target = User::query()->findOrFail($validated['targetUserId']);
        $suspending = $target->status !== 'suspended';

        if ($suspending && $actor->is($target)) {
            $this->addError('suspend', 'You cannot suspend your own account.');

            return;
        }

        if ($suspending && $this->isLastActiveAdministrator($target)) {
            $this->addError('suspend', 'The last active Administrator cannot be suspended.');

            return;
        }

        $oldStatus = $target->status;
        $target->update(['status' => $suspending ? 'suspended' : 'active']);

        if ($suspending) {
            DB::table('sessions')->where('user_id', $target->id)->delete();
        }

        $this->audit($actor, $target, $suspending ? 'user.suspended' : 'user.reactivated', ['status' => $oldStatus], ['status' => $target->status]);
        $this->dispatch('user-changed', userId: $target->id);
        $this->closeModal();
    }

    public function generateResetPassword(): void
    {
        $actor = $this->authorizeWrite();

        $validated = $this->validate([
            'targetUserId' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $target = User::query()->findOrFail($validated['targetUserId']);
        $temporaryPassword = Str::random(16);

        $oldFlag = (bool) $target->must_change_password;

        $target->forceFill([
            'password_hash' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->save();

        DB::table('sessions')->where('user_id', $target->id)->delete();

        $this->audit($actor, $target, 'user.password_reset', ['must_change_password' => $oldFlag], ['must_change_password' => true]);

        $this->temporaryPassword = $temporaryPassword;
        $this->dispatch('user-changed', userId: $target->id);
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        $target = $this->targetUserId !== null
            ? User::query()->with('role')->find($this->targetUserId)
            : null;

        return view('livewire.user-actions-modal', [
            'target' => $target,
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    private function openFor(int $userId, string $action): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        User::query()->findOrFail($userId);

        $this->reset(['action', 'targetUserId', 'roleId', 'temporaryPassword']);
        $this->action = $action;
        $this->targetUserId = $userId;
        $this->isOpen = true;
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        return $user;
    }

    private function isLastActiveAdministrator(User $target): bool
    {
        if ($target->status !== 'active' || $target->role?->slug !== 'administrator') {
            return false;
        }

        return ! User::query()
            ->where('users.id', '!=', $target->getKey())
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'administrator')
            ->where('users.status', 'active')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(User $actor, User $target, string $action, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => $action,
            'table_name' => 'users',
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
