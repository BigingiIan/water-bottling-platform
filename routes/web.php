<?php

use App\Livewire\RolePermissionMatrix;
use App\Livewire\StaffDirectory;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/staff', StaffDirectory::class)->name('staff.directory');
Route::get('/staff/permissions', RolePermissionMatrix::class)->name('staff.permissions');
