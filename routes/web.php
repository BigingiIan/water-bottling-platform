<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\UserImpersonationController;
use App\Livewire\RolePermissionMatrix;
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
    Route::post('/impersonation', [UserImpersonationController::class, 'store'])
        ->name('impersonation.store');
    Route::post('/impersonation/return', [UserImpersonationController::class, 'destroy'])
        ->name('impersonation.destroy');

    Route::view('/dashboard', 'dashboard')
        ->middleware('module.access:dashboard')
        ->name('dashboard');
    Route::get('/staff', StaffDirectory::class)
        ->middleware('module.access:users-roles')
        ->name('staff.directory');
    Route::get('/staff/permissions', RolePermissionMatrix::class)
        ->middleware('module.access:users-roles')
        ->name('staff.permissions');
});
