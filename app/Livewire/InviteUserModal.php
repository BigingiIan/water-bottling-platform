<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class InviteUserModal extends Component
{
    public bool $isOpen = false;

    public string $name = '';

    public string $email = '';

    public ?int $roleId = null;

    public ?string $temporaryPassword = null;

    public string $invitedUserEmail = '';

    #[On('open-invite-modal')]
    public function openModal(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        $this->reset(['name', 'email', 'roleId', 'temporaryPassword', 'invitedUserEmail']);
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset(['name', 'email', 'roleId', 'temporaryPassword', 'invitedUserEmail']);
    }

    public function saveInvite(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'roleId' => ['required', 'integer', Rule::exists('roles', 'id')],
        ]);

        $temporaryPassword = Str::random(16);

        $invited = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($temporaryPassword),
            'role_id' => $validated['roleId'],
            'status' => 'pending',
            'must_change_password' => true,
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'user.created',
            'table_name' => 'users',
        ]);

        $this->reset(['name', 'email', 'roleId']);
        $this->invitedUserEmail = $invited->email;
        $this->temporaryPassword = $temporaryPassword;

        $this->dispatch('user-created', userId: $invited->id);
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        return view('livewire.invite-user-modal', [
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }
}
