<?php

namespace App\Support\Dashboard;

use App\Livewire\Dashboard\EquipmentStatusWidget;
use App\Livewire\Dashboard\ForecastCoverWidget;
use App\Livewire\Dashboard\LeakageReviewWidget;
use App\Livewire\Dashboard\MaintenanceDueWidget;
use App\Livewire\Dashboard\MyRequestsWidget;
use App\Livewire\Dashboard\NotificationsWidget;
use App\Livewire\Dashboard\OpenRunsWidget;
use App\Livewire\Dashboard\OpenWorkOrdersWidget;
use App\Livewire\Dashboard\OrdersTodayWidget;
use App\Livewire\Dashboard\ReceivablesWidget;
use App\Livewire\Dashboard\RecentActivityWidget;
use App\Livewire\Dashboard\StockAlertsWidget;
use App\Livewire\Dashboard\TodaysOutputWidget;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The dashboard widgets, who may see each one, and each user's saved layout.
 *
 * Access is resolved once per user and request, so filtering widgets never issues a query per widget.
 */
class DashboardWidgetRegistry
{
    public const PREFERENCE_KEY = 'dashboard.layout';

    /**
     * Cross-department requests. Widgets and attention items for them are guarded by class_exists so the
     * dashboard keeps working without the feature; the model provides `openFor(User)` and `unreadFor(User)`.
     */
    public const REQUEST_MODEL = 'App\\Models\\DepartmentRequest';

    /**
     * Default widget order per role type. Permitted widgets outside the list start hidden.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_LAYOUTS = [
        'administrator' => ['stock-alerts', 'todays-output', 'orders-today', 'receivables', 'leakage-review', 'equipment-status', 'open-runs', 'forecast-cover', 'recent-activity', 'notifications'],
        'production' => ['todays-output', 'open-runs', 'stock-alerts', 'leakage-review', 'forecast-cover', 'my-requests', 'notifications'],
        'warehouse' => ['stock-alerts', 'orders-today', 'leakage-review', 'forecast-cover', 'my-requests', 'notifications'],
        'sales' => ['orders-today', 'receivables', 'stock-alerts', 'my-requests', 'notifications'],
        'maintenance' => ['maintenance-due', 'open-work-orders', 'equipment-status', 'my-requests', 'notifications'],
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private array $accessLevels = [];

    /**
     * @var array<int, array{order: list<string>, hidden: list<string>}|null>
     */
    private array $savedLayouts = [];

    /**
     * @return array<string, DashboardWidget>
     */
    public function all(): array
    {
        $widgets = [
            new DashboardWidget('todays-output', "Today's output", 'production', 'view', TodaysOutputWidget::class, DashboardWidget::SIZE_SMALL, 90),
            new DashboardWidget('stock-alerts', 'Stock alerts', 'inventory', 'view', StockAlertsWidget::class, DashboardWidget::SIZE_SMALL, 88),
            new DashboardWidget('orders-today', 'Orders today', 'orders', 'view', OrdersTodayWidget::class, DashboardWidget::SIZE_SMALL, 85),
            new DashboardWidget('open-runs', 'Open runs', 'production', 'view', OpenRunsWidget::class, DashboardWidget::SIZE_SMALL, 80),
            new DashboardWidget('receivables', 'Receivables', 'invoicing', 'view', ReceivablesWidget::class, DashboardWidget::SIZE_SMALL, 75,
                available: fn (): bool => class_exists(Invoice::class)),
            new DashboardWidget('leakage-review', 'Leakage review', 'inventory', 'view', LeakageReviewWidget::class, DashboardWidget::SIZE_SMALL, 70),
            new DashboardWidget('my-requests', 'My requests', 'requests', 'view', MyRequestsWidget::class, DashboardWidget::SIZE_SMALL, 65,
                available: fn (): bool => class_exists(self::REQUEST_MODEL), personal: true),
            new DashboardWidget('forecast-cover', "Tomorrow's forecast vs stock cover", 'forecasting', 'view', ForecastCoverWidget::class, DashboardWidget::SIZE_WIDE, 60),
            new DashboardWidget('maintenance-due', 'Maintenance due', 'maintenance', 'view', MaintenanceDueWidget::class, DashboardWidget::SIZE_SMALL, 72),
            new DashboardWidget('open-work-orders', 'Open work orders', 'maintenance', 'view', OpenWorkOrdersWidget::class, DashboardWidget::SIZE_SMALL, 68),
            new DashboardWidget('equipment-status', 'Equipment not operational', 'maintenance', 'view', EquipmentStatusWidget::class, DashboardWidget::SIZE_SMALL, 66),
            new DashboardWidget('notifications', 'Latest notifications', null, 'view', NotificationsWidget::class, DashboardWidget::SIZE_SMALL, 50),
            new DashboardWidget('recent-activity', 'Recent activity', 'settings', 'view', RecentActivityWidget::class, DashboardWidget::SIZE_WIDE, 40,
                administratorOnly: true),
        ];

        return collect($widgets)->keyBy('key')->all();
    }

    /**
     * Module slug => access level ('none', 'view' or 'full') for the user's role, loaded with one query.
     *
     * @return array<string, string>
     */
    public function accessLevels(User $user): array
    {
        return $this->accessLevels[$user->id] ??= $user->role_id === null
            ? []
            : DB::table('role_module_permissions')
                ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
                ->where('role_module_permissions.role_id', $user->role_id)
                ->pluck('role_module_permissions.access_level', 'modules.slug')
                ->all();
    }

    public function canAccessModule(User $user, string $module, string $minimumAccess = 'view'): bool
    {
        $level = $this->accessLevels($user)[$module] ?? 'none';

        return $minimumAccess === 'full' ? $level === 'full' : in_array($level, ['view', 'full'], true);
    }

    public function canSee(User $user, DashboardWidget $widget): bool
    {
        if (! $widget->isAvailable()) {
            return false;
        }

        if ($widget->administratorOnly && $user->role?->slug !== 'administrator') {
            return false;
        }

        return $widget->module === null || $this->canAccessModule($user, $widget->module, $widget->minimumAccess);
    }

    /**
     * Aborts with 403 when a widget component is rendered for someone who may not see it.
     */
    public function authorizeWidget(mixed $user, string $key): User
    {
        $widget = $this->all()[$key] ?? null;

        abort_unless($user instanceof User && $widget !== null && $this->canSee($user, $widget), 403);

        return $user;
    }

    /**
     * @return array<string, DashboardWidget>
     */
    public function permittedFor(User $user): array
    {
        return array_filter($this->all(), fn (DashboardWidget $widget): bool => $this->canSee($user, $widget));
    }

    public function roleTypeFor(User $user): ?string
    {
        $slug = (string) $user->role?->slug;

        return match (true) {
            $slug === 'administrator' => 'administrator',
            str_starts_with($slug, 'production') => 'production',
            str_contains($slug, 'inventory') || str_contains($slug, 'warehouse') => 'warehouse',
            str_contains($slug, 'sales') => 'sales',
            str_contains($slug, 'maintenance') => 'maintenance',
            default => null,
        };
    }

    /**
     * Every permitted widget in display order, with whether the user has it switched on.
     *
     * @return list<array{widget: DashboardWidget, visible: bool}>
     */
    public function layoutFor(User $user): array
    {
        $permitted = $this->permittedFor($user);
        $saved = $this->savedLayout($user);

        if ($saved !== null) {
            $orderedKeys = array_values(array_unique([
                ...array_filter($saved['order'], fn (string $key): bool => isset($permitted[$key])),
                ...array_keys($permitted),
            ]));
            $hiddenKeys = $saved['hidden'];
        } else {
            [$orderedKeys, $hiddenKeys] = $this->defaultLayout($user, $permitted);
        }

        return array_map(fn (string $key): array => [
            'widget' => $permitted[$key],
            'visible' => ! in_array($key, $hiddenKeys, true),
        ], $orderedKeys);
    }

    /**
     * @return list<DashboardWidget>
     */
    public function visibleFor(User $user): array
    {
        return array_values(array_map(
            fn (array $entry): DashboardWidget => $entry['widget'],
            array_filter($this->layoutFor($user), fn (array $entry): bool => $entry['visible']),
        ));
    }

    /**
     * Saves the user's widget order and hidden widgets. Keys the user cannot see are rejected.
     *
     * Hidden widgets the user can no longer see are kept, so they stay hidden if access returns.
     *
     * @param  list<string>  $order
     * @param  list<string>  $hidden
     *
     * @throws ValidationException
     */
    public function saveLayout(User $user, array $order, array $hidden): void
    {
        $permittedKeys = array_keys($this->permittedFor($user));
        $unknownKeys = array_diff([...$order, ...$hidden], $permittedKeys);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(['layout' => 'Your dashboard can only include widgets you have access to.']);
        }

        $previous = $this->savedLayout($user);
        $order = array_values(array_unique(array_map('strval', $order)));
        $hiddenOutOfReach = array_values(array_diff($previous['hidden'] ?? [], $permittedKeys));
        $orderOutOfReach = array_values(array_diff($previous['order'] ?? [], $permittedKeys));
        $layout = [
            'order' => [...$order, ...array_diff($permittedKeys, $order), ...$orderOutOfReach],
            'hidden' => array_values(array_unique([...array_map('strval', $hidden), ...$hiddenOutOfReach])),
        ];

        UserPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => self::PREFERENCE_KEY],
            ['value' => json_encode($layout, JSON_THROW_ON_ERROR)],
        );

        $this->savedLayouts[$user->id] = $layout;
    }

    public function resetLayout(User $user): void
    {
        UserPreference::query()->where('user_id', $user->id)->where('key', self::PREFERENCE_KEY)->delete();
        $this->savedLayouts[$user->id] = null;
    }

    /**
     * @param  array<string, DashboardWidget>  $permitted
     * @return array{0: list<string>, 1: list<string>}
     */
    private function defaultLayout(User $user, array $permitted): array
    {
        $roleLayout = self::ROLE_LAYOUTS[$this->roleTypeFor($user)] ?? null;

        if ($roleLayout === null) {
            $byImportance = $permitted;
            uasort($byImportance, fn (DashboardWidget $a, DashboardWidget $b): int => $b->importance <=> $a->importance);

            return [array_keys($byImportance), []];
        }

        $defaultKeys = array_values(array_filter($roleLayout, fn (string $key): bool => isset($permitted[$key])));
        $otherKeys = array_values(array_diff(array_keys($permitted), $defaultKeys));

        return [[...$defaultKeys, ...$otherKeys], $otherKeys];
    }

    /**
     * @return array{order: list<string>, hidden: list<string>}|null
     */
    private function savedLayout(User $user): ?array
    {
        if (array_key_exists($user->id, $this->savedLayouts)) {
            return $this->savedLayouts[$user->id];
        }

        $stored = UserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', self::PREFERENCE_KEY)
            ->value('value');
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return $this->savedLayouts[$user->id] = is_array($decoded)
            ? [
                'order' => array_values(array_filter((array) ($decoded['order'] ?? []), 'is_string')),
                'hidden' => array_values(array_filter((array) ($decoded['hidden'] ?? []), 'is_string')),
            ]
            : null;
    }
}
