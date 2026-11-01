<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\StudentsController;
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

    Route::get('/academic-years/table', [AcademicYearController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('academic-years.table');

    Route::get('/students', [StudentsController::class, 'index'])
        ->middleware('can:access-dashboard')
        ->name('students.index');

    Route::get('/students/table', [StudentsController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('students.table');

    Route::get('/students/options', [StudentsController::class, 'options'])
        ->middleware('can:access-dashboard')
        ->name('students.options');

    Route::get('/students/{studentId}', [StudentsController::class, 'show'])
        ->middleware('can:access-dashboard')
        ->whereNumber('studentId')
        ->name('students.show');

    Route::post('/students', [StudentsController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('students.store');

    Route::put('/students/{studentId}', [StudentsController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->whereNumber('studentId')
        ->name('students.update');

    Route::patch('/students/{studentId}/status', [StudentsController::class, 'updateStatus'])
        ->middleware('can:access-dashboard')
        ->whereNumber('studentId')
        ->name('students.status');

    Route::patch('/admin/academic-years/{yearId}/status', [AcademicYearController::class, 'changeStatus'])
        ->middleware('can:access-admin')
        ->name('admin.academic-years.status');

    Route::post('/admin/academic-years', [AcademicYearController::class, 'store'])
        ->middleware('can:access-admin')
        ->name('admin.academic-years.store');

    Route::put('/admin/academic-years/{yearId}', [AcademicYearController::class, 'update'])
        ->middleware('can:access-admin')
        ->name('admin.academic-years.update');

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
