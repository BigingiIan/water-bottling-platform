<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * Shortcut buttons for the actions a user is allowed to perform, most useful for their role first.
 */
class DashboardQuickActions
{
    public const LIMIT = 4;

    /**
     * Each action needs full access to its module, the same check the target page makes before acting.
     *
     * @var array<string, array{label: string, icon: string, module: string, route: string, parameters: array<string, string>}>
     */
    public const ACTIONS = [
        'log-production' => ['label' => 'Log production', 'icon' => 'play-circle', 'module' => 'production', 'route' => 'production.runs', 'parameters' => []],
        'schedule-production' => ['label' => 'Schedule production', 'icon' => 'calendar-days', 'module' => 'production', 'route' => 'production.schedule', 'parameters' => []],
        'create-order' => ['label' => 'Create order', 'icon' => 'clipboard-document-list', 'module' => 'orders', 'route' => 'orders.create', 'parameters' => []],
        'record-stock-count' => ['label' => 'Record stock count', 'icon' => 'clipboard-document-check', 'module' => 'inventory', 'route' => 'inventory.overview', 'parameters' => ['open' => 'stock-count']],
        'invite-user' => ['label' => 'Invite user', 'icon' => 'user-plus', 'module' => 'users-roles', 'route' => 'staff.directory', 'parameters' => []],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const ROLE_PRIORITIES = [
        'administrator' => ['create-order', 'record-stock-count', 'schedule-production', 'invite-user'],
        'production' => ['log-production', 'schedule-production', 'record-stock-count'],
        'warehouse' => ['record-stock-count', 'create-order'],
        'sales' => ['create-order'],
    ];

    public function __construct(private DashboardWidgetRegistry $registry) {}

    /**
     * @return list<array{key: string, label: string, icon: string, url: string}>
     */
    public function for(User $user): array
    {
        $priority = self::ROLE_PRIORITIES[$this->registry->roleTypeFor($user)] ?? [];
        $orderedKeys = array_values(array_unique([...$priority, ...array_keys(self::ACTIONS)]));
        $actions = [];

        foreach ($orderedKeys as $key) {
            $action = self::ACTIONS[$key];

            if (! $this->registry->canAccessModule($user, $action['module'], 'full')) {
                continue;
            }

            $actions[] = [
                'key' => $key,
                'label' => $action['label'],
                'icon' => $action['icon'],
                'url' => route($action['route'], $action['parameters']),
            ];
        }

        return array_slice($actions, 0, self::LIMIT);
    }
}
