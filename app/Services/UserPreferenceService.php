<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use App\Support\ModuleAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserPreferenceService
{
    /**
     * Notification preferences can only narrow the global setting of the same key.
     *
     * @var array<string, array{label: string, admin_only?: bool}>
     */
    public const NOTIFICATION_PREFERENCES = [
        'notify.low_stock' => ['label' => 'Low stock alerts'],
        'notify.critical_stock' => ['label' => 'Critical stock alerts'],
        'notify.leakage_flag' => ['label' => 'Leakage flag alerts'],
        'notify.system_errors' => ['label' => 'System error alerts', 'admin_only' => true],
    ];

    /**
     * @var array<string, array{label: string, route: string, module: string}>
     */
    public const LANDING_PAGES = [
        'dashboard' => ['label' => 'Dashboard', 'route' => 'dashboard', 'module' => 'dashboard'],
        'production' => ['label' => 'Production schedule', 'route' => 'production.schedule', 'module' => 'production'],
        'inventory' => ['label' => 'Inventory', 'route' => 'inventory.overview', 'module' => 'inventory'],
    ];

    public const PAGE_SIZES = [15, 25, 50];

    public const LANDING_PAGE_KEY = 'ui.landing_page';

    public const PAGE_SIZE_KEY = 'ui.page_size';

    public const TOUR_KEY = 'tour.welcome_seen_at';

    public const REDUCE_MOTION_KEY = 'ui.reduce_motion';

    public const THEME_KEY = 'ui.theme';

    public const PROFILE_KEY = 'ui.profile';

    public const SIDEBAR_COLLAPSED_KEY = 'ui.sidebar_collapsed';

    public const SIDEBAR_GROUPS_KEY = 'ui.sidebar_collapsed_groups';

    /**
     * Navigation groups that can be collapsed, as slugs of their headings in the app layout.
     *
     * @var list<string>
     */
    public const SIDEBAR_GROUPS = ['production', 'inventory', 'maintenance', 'finance', 'analytics', 'system'];

    /**
     * @var array<int, array<string, string>>
     */
    private array $cache = [];

    public function __construct(
        private SettingsService $settings,
        private ModuleAccess $moduleAccess,
    ) {}

    /**
     * @return array<string, array{label: string, admin_only?: bool}>
     */
    public function notificationPreferencesFor(User $user): array
    {
        $isAdministrator = $user->role?->slug === 'administrator';

        return array_filter(
            self::NOTIFICATION_PREFERENCES,
            fn (array $preference): bool => $isAdministrator || ($preference['admin_only'] ?? false) !== true,
        );
    }

    public function isGloballyEnabled(string $notificationKey): bool
    {
        return (bool) $this->settings->get($notificationKey, true);
    }

    public function isOptedIn(User $user, string $notificationKey): bool
    {
        return ($this->stored($user)[$notificationKey] ?? '1') === '1';
    }

    /**
     * @param  array<int>  $userIds
     * @return array<int>
     */
    public function userIdsOptedOutOf(array $userIds, string $notificationKey): array
    {
        if ($userIds === []) {
            return [];
        }

        return UserPreference::query()
            ->whereIn('user_id', $userIds)
            ->where('key', $notificationKey)
            ->where('value', '0')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, array{label: string, route: string, module: string}>
     */
    public function landingPagesFor(User $user): array
    {
        return array_filter(
            self::LANDING_PAGES,
            fn (array $page): bool => $this->moduleAccess->can($user, $page['module']),
        );
    }

    public function landingPage(User $user): string
    {
        $stored = $this->stored($user)[self::LANDING_PAGE_KEY] ?? 'dashboard';

        return array_key_exists($stored, self::LANDING_PAGES) ? $stored : 'dashboard';
    }

    /**
     * Resolves the stored landing page, re-checking module access so a role change never redirects into a 403.
     */
    public function landingUrl(User $user): string
    {
        $allowedPages = $this->landingPagesFor($user);
        $page = $allowedPages[$this->landingPage($user)] ?? self::LANDING_PAGES['dashboard'];

        return route($page['route']);
    }

    public function pageSize(?User $user, int $pageDefault): int
    {
        if ($user === null) {
            return $pageDefault;
        }

        $stored = (int) ($this->stored($user)[self::PAGE_SIZE_KEY] ?? 0);

        return in_array($stored, self::PAGE_SIZES, true) ? $stored : $pageDefault;
    }

    public function storedPageSize(User $user): ?int
    {
        $stored = (int) ($this->stored($user)[self::PAGE_SIZE_KEY] ?? 0);

        return in_array($stored, self::PAGE_SIZES, true) ? $stored : null;
    }

    /**
     * An empty theme or profile clears the user's own choice, so the organisation default applies again.
     *
     * @param  array{notifications?: array<string, mixed>, landing_page?: mixed, page_size?: mixed, reduce_motion?: mixed, theme?: mixed, profile?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function save(User $user, array $input): void
    {
        $notificationPreferences = $this->notificationPreferencesFor($user);
        $rules = [
            'landing_page' => ['required', 'string', Rule::in(array_keys($this->landingPagesFor($user)))],
            'page_size' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
            'reduce_motion' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('appearance.themes')))],
            'profile' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('appearance.profiles')))],
        ];

        foreach (array_keys($notificationPreferences) as $notificationKey) {
            $rules['notifications.'.$this->fieldFor($notificationKey)] = ['required', 'boolean'];
        }

        $normalizedInput = [
            'landing_page' => $input['landing_page'] ?? null,
            'page_size' => ($input['page_size'] ?? '') === '' ? null : $input['page_size'],
            'notifications' => $input['notifications'] ?? [],
            ...(array_key_exists('reduce_motion', $input) ? ['reduce_motion' => $input['reduce_motion']] : []),
            ...(array_key_exists('theme', $input) ? ['theme' => ($input['theme'] ?? '') === '' ? null : $input['theme']] : []),
            ...(array_key_exists('profile', $input) ? ['profile' => ($input['profile'] ?? '') === '' ? null : $input['profile']] : []),
        ];

        $validator = Validator::make($normalizedInput, $rules, [
            'landing_page.in' => 'Choose a page you have access to.',
            'theme.in' => 'Choose Light, Dark, System or the organisation default.',
            'profile.in' => 'Choose one of the listed colour profiles.',
        ]);
        $validator->after(function ($validator) use ($normalizedInput, $notificationPreferences): void {
            foreach (array_keys($notificationPreferences) as $notificationKey) {
                $field = $this->fieldFor($notificationKey);
                $wantsEnabled = filter_var($normalizedInput['notifications'][$field] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($wantsEnabled && ! $this->isGloballyEnabled($notificationKey)) {
                    $validator->errors()->add(
                        'notifications.'.$field,
                        'This alert is turned off in Settings and cannot be enabled here.',
                    );
                }
            }
        });

        $validated = $validator->validate();
        $values = [
            self::LANDING_PAGE_KEY => (string) $validated['landing_page'],
            self::PAGE_SIZE_KEY => $validated['page_size'] === null ? null : (string) $validated['page_size'],
        ];

        if (array_key_exists('reduce_motion', $validated)) {
            $values[self::REDUCE_MOTION_KEY] = filter_var($validated['reduce_motion'], FILTER_VALIDATE_BOOLEAN) ? '1' : null;
        }

        if (array_key_exists('theme', $validated)) {
            $values[self::THEME_KEY] = $validated['theme'];
        }

        if (array_key_exists('profile', $validated)) {
            $values[self::PROFILE_KEY] = $validated['profile'];
        }

        foreach (array_keys($notificationPreferences) as $notificationKey) {
            $isEnabled = filter_var($validated['notifications'][$this->fieldFor($notificationKey)], FILTER_VALIDATE_BOOLEAN);

            // A globally disabled alert keeps the user's earlier choice so it returns when Settings re-enables it.
            if (! $this->isGloballyEnabled($notificationKey)) {
                continue;
            }

            $values[$notificationKey] = $isEnabled ? '1' : '0';
        }

        DB::transaction(function () use ($user, $values): void {
            foreach ($values as $key => $value) {
                if ($value === null) {
                    $user->preferences()->where('key', $key)->delete();

                    continue;
                }

                $user->preferences()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        unset($this->cache[$user->id]);
    }

    public function hasSeenWelcomeTour(User $user): bool
    {
        return array_key_exists(self::TOUR_KEY, $this->stored($user));
    }

    /**
     * Recorded when the tour first opens, so it runs once even if the user leaves part-way; Replay reopens it.
     */
    public function markWelcomeTourSeen(User $user): void
    {
        $user->preferences()->updateOrCreate(['key' => self::TOUR_KEY], ['value' => now()->toIso8601String()]);
        unset($this->cache[$user->id]);
    }

    /**
     * The profile's "Reduce motion": no splash and no decorative animation, on top of the OS setting.
     */
    public function reduceMotion(User $user): bool
    {
        return ($this->stored($user)[self::REDUCE_MOTION_KEY] ?? '0') === '1';
    }

    /**
     * The user's own theme mode, or null to use the organisation default.
     */
    public function themePreference(User $user): ?string
    {
        return $this->stored($user)[self::THEME_KEY] ?? null;
    }

    /**
     * The user's own colour profile, or null to use the organisation default.
     */
    public function profilePreference(User $user): ?string
    {
        return $this->stored($user)[self::PROFILE_KEY] ?? null;
    }

    /**
     * Whether the desktop sidebar is shrunk to the icon rail.
     */
    public function sidebarCollapsed(User $user): bool
    {
        return ($this->stored($user)[self::SIDEBAR_COLLAPSED_KEY] ?? '0') === '1';
    }

    /**
     * @return list<string> navigation group slugs the user has collapsed
     */
    public function collapsedSidebarGroups(User $user): array
    {
        $groups = json_decode($this->stored($user)[self::SIDEBAR_GROUPS_KEY] ?? '[]', true);

        return is_array($groups) ? array_values(array_intersect(self::SIDEBAR_GROUPS, $groups)) : [];
    }

    public function saveSidebarCollapsed(User $user, bool $collapsed): void
    {
        $user->preferences()->updateOrCreate(['key' => self::SIDEBAR_COLLAPSED_KEY], ['value' => $collapsed ? '1' : '0']);
        unset($this->cache[$user->id]);
    }

    public function saveSidebarGroup(User $user, string $group, bool $collapsed): void
    {
        if (! in_array($group, self::SIDEBAR_GROUPS, true)) {
            throw ValidationException::withMessages(['group' => 'Unknown navigation group.']);
        }

        $groups = array_diff($this->collapsedSidebarGroups($user), [$group]);

        if ($collapsed) {
            $groups[] = $group;
        }

        $user->preferences()->updateOrCreate(
            ['key' => self::SIDEBAR_GROUPS_KEY],
            ['value' => json_encode(array_values(array_intersect(self::SIDEBAR_GROUPS, $groups)))],
        );
        unset($this->cache[$user->id]);
    }

    public function fieldFor(string $notificationKey): string
    {
        return str_replace('.', '_', $notificationKey);
    }

    /**
     * @return array<string, string>
     */
    private function stored(User $user): array
    {
        return $this->cache[$user->id] ??= $user->preferences()->pluck('value', 'key')->all();
    }
}
