<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordSetController;
use App\Http\Controllers\UserImpersonationController;
use App\Livewire\Dashboard;
use App\Livewire\ErrorLogs;
use App\Livewire\ForecastingPage;
use App\Livewire\InventoryOverview;
use App\Livewire\NotificationsPage;
use App\Livewire\ProductionHistory;
use App\Livewire\ProductionRuns;
use App\Livewire\ProfilePage;
use App\Livewire\RolePermissionMatrix;
use App\Livewire\SettingsPage;
use App\Livewire\StaffDirectory;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/password/set', [PasswordSetController::class, 'show'])->name('password.set');
    Route::post('/password/set', [PasswordSetController::class, 'update'])->name('password.set.update');
    Route::post('/impersonation', [UserImpersonationController::class, 'store'])
        ->name('impersonation.store');
    Route::post('/impersonation/return', [UserImpersonationController::class, 'destroy'])
        ->name('impersonation.destroy');

    Route::get('/dashboard', Dashboard::class)
        ->middleware('module.access:dashboard')
        ->name('dashboard');
    Route::get('/forecasting', ForecastingPage::class)
        ->middleware('module.access:forecasting')
        ->name('forecasting.index');
    Route::get('/staff', StaffDirectory::class)
        ->middleware('module.access:users-roles')
        ->name('staff.directory');
    Route::get('/staff/permissions', RolePermissionMatrix::class)
        ->middleware('module.access:users-roles')
        ->name('staff.permissions');
    Route::get('/system/errors', ErrorLogs::class)
        ->middleware('module.access:error-logs')
        ->name('errors.index');
    Route::get('/settings', SettingsPage::class)
        ->middleware('module.access:settings')
        ->name('settings.index');
    Route::get('/audit-logs', fn () => redirect()->route('settings.index', ['tab' => 'audit']))
        ->middleware('module.access:settings')
        ->name('audit-logs.redirect');
    Route::get('/notifications', NotificationsPage::class)->name('notifications.index');
    Route::get('/profile', ProfilePage::class)->name('profile.show');
    Route::post('/access-requests', [AccessRequestController::class, 'store'])->name('access-requests.store');
    Route::get('/inventory', InventoryOverview::class)
        ->middleware('module.access:inventory')
        ->name('inventory.overview');

    Route::view('/production/schedule', 'production.schedule')
        ->middleware('module.access:production')
        ->name('production.schedule');
    Route::get('/production/runs', ProductionRuns::class)
        ->middleware('module.access:production')
        ->name('production.runs');
    Route::get('/production/history', ProductionHistory::class)
        ->middleware('module.access:production')
        ->name('production.history');
});
