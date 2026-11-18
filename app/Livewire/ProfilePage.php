<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AppearanceService;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use App\Support\Appearance\Palette;
use App\Support\Impersonation;
use App\Support\ModuleAccess;
use App\Support\Plural;
use App\Support\UserAgentParser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'My Profile', 'breadcrumbSection' => 'Account', 'activeItem' => 'My Profile'])]
class ProfilePage extends Component
{
    private const TABS = ['details', 'password', 'sessions', 'preferences'];

    private const PASSWORD_ATTEMPTS = 5;

    private const PASSWORD_DECAY_SECONDS = 600;

    #[Url(as: 'tab', except: 'details')]
    public string $activeTab = 'details';

    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $showLogoutSessionsModal = false;

    public string $sessionsPassword = '';

    /**
     * @var array<string, bool>
     */
    public array $notificationPreferences = [];

    public string $landingPage = 'dashboard';

    public string $pageSize = '';

    public bool $reduceMotion = false;

    /**
     * The user's own theme mode; empty means the organisation default.
     */
    public string $theme = '';

    /**
     * The user's own colour profile; empty means the organisation default.
     */
    public string $profile = '';

    public function mount(UserPreferenceService $preferences): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'details';
        }

        $this->fillDetails();
        $this->fillPreferences($preferences);
    }

    public function updatedActiveTab(UserPreferenceService $preferences): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'details';
        }

        $this->resetValidation();
        $this->resetPasswordFields();
        $this->fillDetails();
        $this->fillPreferences($preferences);
    }

    public function saveDetails(): void
    {
        $user = $this->editableUser();
        $canEditEmail = $this->canEditEmail($user);

        $rules = ['name' => ['required', 'string', 'max:255']];

        if ($canEditEmail) {
            $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)];
        }

        $validated = $this->validate($rules);
        $newName = trim($validated['name']);
        $newEmail = $canEditEmail ? $validated['email'] : $user->email;

        DB::transaction(function () use ($user, $newName, $newEmail): void {
            if ($newName !== $user->name) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'target_user_id' => $user->id,
                    'action' => 'profile.name_changed',
                    'table_name' => 'users',
                    'old_values' => ['name' => $user->name],
                    'new_values' => ['name' => $newName],
                ]);
            }

            if ($newEmail !== $user->email) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'target_user_id' => $user->id,
                    'action' => 'profile.email_changed',
                    'table_name' => 'users',
                    'old_values' => ['email' => $user->email],
                    'new_values' => ['email' => $newEmail],
                ]);
            }

            $user->forceFill(['name' => $newName, 'email' => $newEmail])->save();
        });

        $this->fillDetails();
        $this->dispatch('profile-saved', message: 'Profile updated.');
    }

    public function changePassword(SettingsService $settings): void
    {
        $user = $this->editableUser();
        $this->ensurePasswordAttemptsRemain($user, 'currentPassword');

        try {
            $validated = $this->validate([
                'currentPassword' => ['required', 'string', 'current_password:web'],
                'newPassword' => [
                    'required',
                    'string',
                    'min:'.$settings->get('security.password_min_length', 8),
                    'max:255',
                    'different:currentPassword',
                ],
                'newPasswordConfirmation' => ['required', 'same:newPassword'],
            ], [
                'currentPassword.current_password' => 'The current password is incorrect.',
                'newPassword.different' => 'The new password must differ from your current password.',
                'newPasswordConfirmation.same' => 'The password confirmation does not match.',
            ]);
        } catch (ValidationException $exception) {
            $this->resetPasswordFields();

            throw $exception;
        }

        $removedSessions = DB::transaction(function () use ($user, $validated): int {
            $user->forceFill([
                'password_hash' => Hash::make($validated['newPassword']),
                'must_change_password' => false,
                'status' => $user->status === 'pending' ? 'active' : $user->status,
            ])->save();

            $removedSessions = $this->deleteOtherSessions($user);

            AuditLog::create([
                'user_id' => $user->id,
                'target_user_id' => $user->id,
                'action' => 'profile.password_changed',
                'table_name' => 'users',
                'new_values' => ['other_sessions_logged_out' => $removedSessions],
            ]);

            return $removedSessions;
        });

        RateLimiter::clear($this->passwordThrottleKey($user));
        $this->resetPasswordFields();
        $this->dispatch('profile-saved', message: $removedSessions > 0
            ? 'Password changed. Your other sessions were signed out.'
            : 'Password changed.');
    }

    public function openLogoutSessionsModal(): void
    {
        $this->editableUser();
        $this->resetValidation();
        $this->sessionsPassword = '';
        $this->showLogoutSessionsModal = true;
    }

    public function closeLogoutSessionsModal(): void
    {
        $this->sessionsPassword = '';
        $this->showLogoutSessionsModal = false;
        $this->resetValidation();
    }

    public function logoutOtherSessions(): void
    {
        $user = $this->editableUser();
        $this->ensurePasswordAttemptsRemain($user, 'sessionsPassword');

        try {
            $this->validate(
                ['sessionsPassword' => ['required', 'string', 'current_password:web']],
                ['sessionsPassword.current_password' => 'The password is incorrect.'],
            );
        } catch (ValidationException $exception) {
            $this->sessionsPassword = '';

            throw $exception;
        }

        $removedSessions = $this->deleteOtherSessions($user);

        AuditLog::create([
            'user_id' => $user->id,
            'target_user_id' => $user->id,
            'action' => 'profile.sessions_logged_out',
            'table_name' => 'sessions',
            'new_values' => ['sessions_removed' => $removedSessions],
        ]);

        RateLimiter::clear($this->passwordThrottleKey($user));
        $this->closeLogoutSessionsModal();
        $this->dispatch('profile-saved', message: 'Signed out of '.Plural::count($removedSessions, 'other session').'.');
    }

    public function savePreferences(UserPreferenceService $preferences): void
    {
        $user = $this->editableUser();

        $preferences->save($user, [
            'notifications' => $this->notificationPreferences,
            'landing_page' => $this->landingPage,
            'page_size' => $this->pageSize,
            'reduce_motion' => $this->reduceMotion,
            'theme' => $this->theme,
            'profile' => $this->profile,
        ]);

        $this->resetValidation();
        $this->fillPreferences($preferences);
        $this->dispatchAppearance($user);
        $this->dispatch('profile-saved', message: 'Preferences saved.');
    }

    public function render(UserPreferenceService $preferences): View
    {
        $user = $this->currentUser();
        $isViewingAs = Impersonation::active();

        return view('livewire.profile-page', [
            'user' => $user,
            'isViewingAs' => $isViewingAs,
            'canEditEmail' => $this->canEditEmail($user),
            'passwordMinimumLength' => (int) app(SettingsService::class)->get('security.password_min_length', 8),
            'sessions' => $this->activeTab === 'sessions' ? $this->sessionsFor($user) : collect(),
            'tracksSessions' => config('session.driver') === 'database',
            'notificationOptions' => $preferences->notificationPreferencesFor($user),
            'landingPageOptions' => $preferences->landingPagesFor($user),
            'pageSizeOptions' => UserPreferenceService::PAGE_SIZES,
            'themeOptions' => app(Palette::class)->themes(),
            'profileOptions' => app(Palette::class)->profiles(),
            'organisationAppearance' => app(AppearanceService::class)->organisationDefault(),
            'preferences' => $preferences,
        ]);
    }

    /**
     * The profile always belongs to the signed-in user; no user id is ever read from the request.
     */
    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * View As sessions are read-only so an administrator cannot change another user's credentials or preferences.
     */
    private function editableUser(): User
    {
        $user = $this->currentUser();

        abort_if(Impersonation::active(), 403, 'Profiles are read-only while using View As.');

        return $user;
    }

    private function canEditEmail(User $user): bool
    {
        return app(ModuleAccess::class)->can($user, 'users-roles', write: true);
    }

    private function ensurePasswordAttemptsRemain(User $user, string $field): void
    {
        $throttleKey = $this->passwordThrottleKey($user);

        if (RateLimiter::tooManyAttempts($throttleKey, self::PASSWORD_ATTEMPTS)) {
            $this->resetPasswordFields();
            $this->sessionsPassword = '';

            throw ValidationException::withMessages([
                $field => 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($throttleKey) / 60).' minutes.',
            ]);
        }

        RateLimiter::hit($throttleKey, self::PASSWORD_DECAY_SECONDS);
    }

    private function passwordThrottleKey(User $user): string
    {
        return 'profile-password:'.$user->id;
    }

    private function deleteOtherSessions(User $user): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    /**
     * @return Collection<int, array{id: string, browser: string, platform: string, ip_address: ?string, last_active: Carbon, is_current: bool}>
     */
    private function sessionsFor(User $user): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $currentSessionId = session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => hash('sha256', $session->id),
                ...UserAgentParser::parse($session->user_agent),
                'ip_address' => $session->ip_address,
                'last_active' => Carbon::createFromTimestamp($session->last_activity),
                'is_current' => $session->id === $currentSessionId,
            ])
            ->sortByDesc('is_current')
            ->values();
    }

    private function fillDetails(): void
    {
        $user = $this->currentUser();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    private function fillPreferences(UserPreferenceService $preferences): void
    {
        $user = $this->currentUser();
        $this->notificationPreferences = [];

        foreach (array_keys($preferences->notificationPreferencesFor($user)) as $notificationKey) {
            $this->notificationPreferences[$preferences->fieldFor($notificationKey)] = $preferences->isGloballyEnabled($notificationKey)
                && $preferences->isOptedIn($user, $notificationKey);
        }

        $landingPage = $preferences->landingPage($user);
        $this->landingPage = array_key_exists($landingPage, $preferences->landingPagesFor($user)) ? $landingPage : 'dashboard';
        $this->pageSize = (string) ($preferences->storedPageSize($user) ?? '');
        $this->reduceMotion = $preferences->reduceMotion($user);
        $this->theme = (string) $preferences->themePreference($user);
        $this->profile = (string) $preferences->profilePreference($user);
    }

    /**
     * Repaints the open page in the saved theme, the same tokens the next page load renders on the server.
     */
    private function dispatchAppearance(User $user): void
    {
        $appearance = app(AppearanceService::class)->resolve($user);

        $this->dispatch(
            'appearance-changed',
            theme: $appearance['theme'],
            profile: $appearance['profile'],
            stylesheet: app(Palette::class)->rootStylesheet($appearance['profile'], $appearance['theme']),
        );
    }

    private function resetPasswordFields(): void
    {
        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }
}
