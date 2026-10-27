<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\DashboardWidgetRegistry;
use Livewire\Component;

/**
 * Base for dashboard widgets. Every render, including poll refreshes, re-checks that the user may see
 * the widget before any of its data is loaded.
 */
abstract class WidgetComponent extends Component
{
    abstract public static function key(): string;

    protected function authorizeWidget(): User
    {
        return app(DashboardWidgetRegistry::class)->authorizeWidget(auth()->user(), static::key());
    }

    protected function metrics(): DashboardMetrics
    {
        return app(DashboardMetrics::class);
    }
}
