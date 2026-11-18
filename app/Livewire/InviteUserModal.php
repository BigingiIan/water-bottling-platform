<?php

namespace App\Livewire;

use App\Mail\UserInvitation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class InviteUserModal extends Component
{
    /**
     * How long an invitation or administrator-reset temporary password stays usable.
     */
    public const TEMPORARY_PASSWORD_LIFETIME_HOURS = 72;

    public bool $isOpen = false;

    public string $name = '';

    public string $email = '';

    public ?int $roleId = null;

    public string $passwordMode = 'generate';

    public string $customPassword = '';

    public string $customPassword_confirmation = '';

    public ?string $temporaryPassword = null;

    public string $invitedUserEmail = '';

    public bool $emailSent = false;

    #[On('open-invite-modal')]
    public function openModal(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        $this->reset([
            'name',
            'email',
            'roleId',
            'passwordMode',
            'customPassword',
            'customPassword_confirmation',
            'temporaryPassword',
            'invitedUserEmail',
            'emailSent',
        ]);
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset([
            'name',
            'email',
            'roleId',
            'passwordMode',
            'customPassword',
            'customPassword_confirmation',
            'temporaryPassword',
            'invitedUserEmail',
            'emailSent',
        ]);
    }

    public function saveInvite(SettingsService $settings): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);
        $requiresPasswordChange = $settings->get('security.require_password_change_on_first_login', true);
        $passwordMinimumLength = $settings->get('security.password_min_length', 8);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'roleId' => ['required', 'integer', Rule::exists('roles', 'id')],
            'passwordMode' => ['required', Rule::in(['generate', 'custom'])],
            'customPassword' => ['exclude_unless:passwordMode,custom', 'required', 'string', 'min:'.$passwordMinimumLength, 'max:255', 'confirmed'],
        ]);

        $invitedRoleSlug = Role::query()->whereKey($validated['roleId'])->value('slug');

        if ($invitedRoleSlug === 'administrator' && $user->role?->slug !== 'administrator') {
            $this->addError('roleId', 'Only an Administrator can invite another Administrator.');

            return;
        }

        $temporaryPassword = $validated['passwordMode'] === 'custom'
            ? $validated['customPassword']
            : Str::random(16);

        $invited = DB::transaction(function () use ($user, $validated, $temporaryPassword, $requiresPasswordChange): User {
            $invited = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password_hash' => Hash::make($temporaryPassword),
                'role_id' => $validated['roleId'],
                'status' => $requiresPasswordChange ? 'pending' : 'active',
                'must_change_password' => $requiresPasswordChange,
                'temporary_password_expires_at' => $requiresPasswordChange
                    ? now()->addHours(self::TEMPORARY_PASSWORD_LIFETIME_HOURS)
                    : null,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'target_user_id' => $invited->id,
                'action' => 'user.created',
                'table_name' => 'users',
                'old_values' => [],
                'new_values' => [
                    'name' => $invited->name,
                    'email' => $invited->email,
                    'role' => $invited->role?->slug,
                    'status' => $invited->status,
                    'must_change_password' => $invited->must_change_password,
                ],
            ]);

            return $invited;
        });

        $this->invitedUserEmail = $invited->email;
        $this->temporaryPassword = $temporaryPassword;

        try {
            Mail::to($invited)->send(new UserInvitation(
                $invited->name,
                $invited->email,
                $temporaryPassword,
            ));
            $this->emailSent = true;
        } catch (TransportExceptionInterface) {
            $this->addError(
                'emailDelivery',
                'The account was created, but the invitation email could not be sent. Share the temporary password securely and check the mail configuration.',
            );
        }

        $this->reset(['name', 'email', 'roleId', 'passwordMode', 'customPassword', 'customPassword_confirmation']);
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
