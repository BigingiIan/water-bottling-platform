<?php

namespace App\Providers;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\DepartmentRequest;
use App\Models\User;
use App\Services\AppearanceService;
use App\Services\Calendar\MonthlyMeanTemperature;
use App\Services\Calendar\TemperatureSource;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\MLForecastClient;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use App\Support\Dashboard\DashboardWidgetRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(UserPreferenceService::class);
        $this->app->scoped(MLForecastClient::class);
        $this->app->scoped(DashboardWidgetRegistry::class);
        $this->app->scoped(DashboardMetrics::class);
        $this->app->scoped(TemperatureSource::class, MonthlyMeanTemperature::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Every full page resolves its theme and colour profile here, so <html> and the token styles are server-rendered.
        View::composer(['components.app-layout', 'welcome', 'auth.login', 'auth.password-set'], function (ViewInstance $view): void {
            $user = auth()->user();
            $view->with('appearance', app(AppearanceService::class)->resolve($user instanceof User ? $user : null));
        });

        View::composer('components.app-layout', function (ViewInstance $view): void {
            $user = Auth::user();
            $isImpersonating = session()->has('impersonator_id');
            $impersonator = $isImpersonating
                ? User::query()->find(session('impersonator_id'))
                : null;
            $canImpersonate = ! $isImpersonating
                && $user?->status === 'active'
                && $user?->role?->slug === 'administrator';
            $impersonationUsers = $canImpersonate
                ? User::query()
                    ->with('role')
                    ->where('status', 'active')
                    ->where('id', '!=', $user->getKey())
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'role_id'])
                : collect();
            $accessibleModuleSlugs = $user?->role_id
                ? DB::table('role_module_permissions')
                    ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
                    ->where('role_module_permissions.role_id', $user->role_id)
                    ->whereIn('role_module_permissions.access_level', ['view', 'full'])
                    ->pluck('modules.slug')
                    ->all()
                : [];
            $fullAccessModuleSlugs = $user?->role_id
                ? DB::table('role_module_permissions')
                    ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
                    ->where('role_module_permissions.role_id', $user->role_id)
                    ->where('role_module_permissions.access_level', 'full')
                    ->pluck('modules.slug')
                    ->all()
                : [];

            $unreadNotificationCount = $user instanceof User ? $user->unreadNotifications()->count() : 0;
            $openRequestCount = $user instanceof User && in_array('requests', $accessibleModuleSlugs, true)
                ? DepartmentRequest::query()->openFor($user)->count()
                : 0;

            $preferences = app(UserPreferenceService::class);
            $sidebarCollapsed = $user instanceof User && $preferences->sidebarCollapsed($user);
            $collapsedNavGroups = $user instanceof User ? $preferences->collapsedSidebarGroups($user) : [];
            $reduceMotion = $user instanceof User && $preferences->reduceMotion($user);
            // Always taken, so it can't resurface later; with reduced motion it is simply not shown.
            $showSplash = (bool) session()->pull(AuthenticatedSessionController::SPLASH_SESSION_KEY, false) && ! $reduceMotion;

            $view->with(compact(
                'accessibleModuleSlugs',
                'collapsedNavGroups',
                'reduceMotion',
                'showSplash',
                'sidebarCollapsed',
                'canImpersonate',
                'fullAccessModuleSlugs',
                'impersonationUsers',
                'impersonator',
                'isImpersonating',
                'openRequestCount',
                'unreadNotificationCount',
            ));
        });
    }
}
