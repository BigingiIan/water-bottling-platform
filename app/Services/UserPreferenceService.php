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
     * @param  array{notifications?: array<string, mixed>, landing_page?: mixed, page_size?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function save(User $user, array $input): void
    {
        $notificationPreferences = $this->notificationPreferencesFor($user);
        $rules = [
            'landing_page' => ['required', 'string', Rule::in(array_keys($this->landingPagesFor($user)))],
            'page_size' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ];

        foreach (array_keys($notificationPreferences) as $notificationKey) {
            $rules['notifications.'.$this->fieldFor($notificationKey)] = ['required', 'boolean'];
        }

        $normalizedInput = [
            'landing_page' => $input['landing_page'] ?? null,
            'page_size' => ($input['page_size'] ?? '') === '' ? null : $input['page_size'],
            'notifications' => $input['notifications'] ?? [],
        ];

        $validator = Validator::make($normalizedInput, $rules, [
            'landing_page.in' => 'Choose a page you have access to.',
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
