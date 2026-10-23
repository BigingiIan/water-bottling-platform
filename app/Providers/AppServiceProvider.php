<?php

namespace App\Providers;

use App\Models\User;
use App\Services\MLForecastClient;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

            $view->with(compact(
                'accessibleModuleSlugs',
                'canImpersonate',
                'fullAccessModuleSlugs',
                'impersonationUsers',
                'impersonator',
                'isImpersonating',
                'unreadNotificationCount',
            ));
        });
    }
}
