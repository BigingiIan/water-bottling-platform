<?php

namespace App\Services\Help;

use App\Models\Module;
use App\Models\User;
use App\Support\Dashboard\DashboardWidgetRegistry;

/**
 * The welcome tour, adapted to the user's role: steps that point at something the role can't see are dropped.
 */
class WelcomeTourSteps
{
    /**
     * Each step's target is a `data-tour` value in the layout; null centres the step on screen.
     *
     * @var array<string, array{target: ?string, module?: string}>
     */
    public const STEPS = [
        'welcome' => ['target' => null],
        'sidebar' => ['target' => 'sidebar'],
        'requests' => ['target' => 'nav-requests', 'module' => 'requests'],
        'notifications' => ['target' => 'notifications'],
        'profile' => ['target' => 'profile-menu'],
        'dashboard' => ['target' => 'nav-dashboard', 'module' => 'dashboard'],
        'help' => ['target' => 'help-link'],
    ];

    public function __construct(private DashboardWidgetRegistry $registry) {}

    /**
     * @return list<array{key: string, target: ?string, title: string, body: string}>
     */
    public function for(User $user): array
    {
        $steps = [];
        $moduleNames = null;

        foreach (self::STEPS as $key => $step) {
            if (isset($step['module']) && ! $this->registry->canAccessModule($user, $step['module'])) {
                continue;
            }

            $moduleNames ??= $this->accessibleModuleNames($user);

            $steps[] = [
                'key' => $key,
                'target' => $step['target'],
                'title' => __("help.tour.steps.{$key}.title", ['name' => $user->name]),
                'body' => __("help.tour.steps.{$key}.body", [
                    'role' => $user->role?->name ?? __('help.levels.none'),
                    'modules' => $moduleNames,
                ]),
            ];
        }

        return $steps;
    }

    private function accessibleModuleNames(User $user): string
    {
        $slugs = array_keys(array_filter(
            $this->registry->accessLevels($user),
            fn (string $level): bool => in_array($level, ['view', 'full'], true),
        ));

        return Module::query()->whereIn('slug', $slugs)->orderBy('name')->pluck('name')->implode(', ');
    }
}
