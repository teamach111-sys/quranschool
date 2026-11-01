<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchoolSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes (login only, no registration)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
});

// Kept outside the "guest" middleware on purpose: when an old tab (or a double
// submit) posts the form while already authenticated, "guest" would answer with
// a 302 to the dashboard. The fetch then follows the redirect and receives HTML,
// which made the login form show "Something went wrong". Always return JSON.
Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware('can:access-dashboard')->name('dashboard');

    Route::get('/academic-years', [AcademicYearController::class, 'index'])
        ->middleware('can:access-dashboard')
        ->name('academic-years.index');

    Route::get('/admin/dashboard-summary', AdminDashboardController::class)
        ->middleware('can:access-admin')
        ->name('admin.dashboard-summary');

    Route::get('/admin/school-settings', [SchoolSettingsController::class, 'show'])
        ->middleware('can:access-admin')
        ->name('admin.school-settings.show');

    Route::put('/admin/school-settings', [SchoolSettingsController::class, 'update'])
        ->middleware('can:access-admin')
        ->name('admin.school-settings.update');
});
