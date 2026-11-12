<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordSetController;
use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\SidebarPreferenceController;
use App\Http\Controllers\UserImpersonationController;
use App\Livewire\CalendarPage;
use App\Livewire\Dashboard;
use App\Livewire\ErrorLogs;
use App\Livewire\ForecastingPage;
use App\Livewire\HelpCentre;
use App\Livewire\InventoryOverview;
use App\Livewire\InvoiceDetail;
use App\Livewire\InvoicesPage;
use App\Livewire\Maintenance\EquipmentDetail;
use App\Livewire\Maintenance\EquipmentList;
use App\Livewire\Maintenance\MaintenanceCalendar;
use App\Livewire\Maintenance\WorkOrderDetail;
use App\Livewire\Maintenance\WorkOrdersBoard;
use App\Livewire\NotificationsPage;
use App\Livewire\OrderDetail;
use App\Livewire\OrderEditor;
use App\Livewire\OrdersPage;
use App\Livewire\ProductionHistory;
use App\Livewire\ProductionRuns;
use App\Livewire\ProfilePage;
use App\Livewire\RequestDetail;
use App\Livewire\RequestsPage;
use App\Livewire\RevenuePage;
use App\Livewire\RolePermissionMatrix;
use App\Livewire\SettingsPage;
use App\Livewire\StaffDirectory;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingPageController::class)->name('home');
Route::get('/branding/logo', BrandingLogoController::class)->name('branding.logo');

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
    Route::middleware('module.access:maintenance')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/', EquipmentList::class)->name('equipment.index');
        Route::get('/equipment/{equipment}', EquipmentDetail::class)->name('equipment.show');
        Route::get('/work-orders', WorkOrdersBoard::class)->name('work-orders.index');
        Route::get('/work-orders/{workOrder}', WorkOrderDetail::class)->name('work-orders.show');
        Route::get('/calendar', MaintenanceCalendar::class)->name('calendar');
    });
    Route::get('/calendar', CalendarPage::class)
        ->middleware('module.access:calendar')
        ->name('calendar.index');
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
    Route::get('/help', HelpCentre::class)->name('help');
    Route::middleware('module.access:requests')->group(function () {
        Route::get('/requests', RequestsPage::class)->name('requests.index');
        Route::get('/requests/{departmentRequest}', RequestDetail::class)->name('requests.show');
    });
    Route::get('/profile', ProfilePage::class)->name('profile.show');
    Route::patch('/preferences/sidebar', [SidebarPreferenceController::class, 'update'])->name('preferences.sidebar');
    Route::post('/access-requests', [AccessRequestController::class, 'store'])->name('access-requests.store');
    Route::middleware('module.access:orders')->group(function () {
        Route::get('/orders', OrdersPage::class)->name('orders.index');
        Route::get('/orders/create', OrderEditor::class)->name('orders.create');
        Route::get('/orders/{order}', OrderDetail::class)->name('orders.show');
        Route::get('/orders/{order}/edit', OrderEditor::class)->name('orders.edit');
    });
    Route::middleware('module.access:invoicing')->group(function () {
        Route::get('/invoices', InvoicesPage::class)->name('invoices.index');
        Route::get('/invoices/{invoice}', InvoiceDetail::class)->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
    });
    Route::get('/revenue', RevenuePage::class)
        ->middleware('module.access:revenue')
        ->name('revenue.index');
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
