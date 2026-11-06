<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ActionHistoryController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MemorizationSessionsController;
use App\Http\Controllers\PaymentsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleChangeHistoryController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\SchedulesController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\SecretaryDashboardController;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\TeacherDashboardController;
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

    Route::get('/students/export', [StudentsController::class, 'export'])
        ->middleware('can:access-dashboard')
        ->name('students.export');

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

    Route::get('/rooms/table', [RoomsController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('rooms.table');

    Route::get('/rooms/export', [RoomsController::class, 'export'])
        ->middleware('can:access-dashboard')
        ->name('rooms.export');

    Route::post('/rooms', [RoomsController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('rooms.store');

    Route::put('/rooms/{roomId}', [RoomsController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->whereNumber('roomId')
        ->name('rooms.update');

    Route::patch('/rooms/{roomId}/archive', [RoomsController::class, 'archive'])
        ->middleware('can:access-dashboard')
        ->whereNumber('roomId')
        ->name('rooms.archive');

    Route::post('/rooms/{roomId}/teachers', [RoomsController::class, 'assignTeacher'])
        ->middleware('can:access-dashboard')
        ->whereNumber('roomId')
        ->name('rooms.teachers.store');

    Route::patch('/rooms/{roomId}/teachers/{teacherId}/end', [RoomsController::class, 'endTeacherAssignment'])
        ->middleware('can:access-dashboard')
        ->whereNumber('roomId')
        ->whereNumber('teacherId')
        ->name('rooms.teachers.end');

    Route::patch('/students/{studentId}/room', [RoomsController::class, 'placeStudent'])
        ->middleware('can:access-dashboard')
        ->whereNumber('studentId')
        ->name('students.room');

    Route::patch('/students/room', [RoomsController::class, 'placeStudents'])
        ->middleware('can:access-dashboard')
        ->name('students.rooms.bulk');

    Route::get('/schedules/table', [SchedulesController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('schedules.table');

    Route::get('/schedules/export', [SchedulesController::class, 'export'])
        ->middleware('can:access-dashboard')
        ->name('schedules.export');

    Route::get('/attendance/table', [AttendanceController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('attendance.table');

    Route::get('/attendance/export', [AttendanceController::class, 'export'])
        ->middleware('can:access-dashboard')
        ->name('attendance.export');

    Route::get('/attendance/roster', [AttendanceController::class, 'roster'])
        ->middleware('can:access-dashboard')
        ->name('attendance.roster');

    Route::get('/memorization-sessions/table', [MemorizationSessionsController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('memorization-sessions.table');

    Route::get('/memorization-sessions/export', [MemorizationSessionsController::class, 'export'])
        ->middleware('can:access-dashboard')
        ->name('memorization-sessions.export');

    Route::get('/memorization-sessions/progress', [MemorizationSessionsController::class, 'progress'])
        ->middleware('can:access-dashboard')
        ->name('memorization-sessions.progress');

    Route::get('/payments/table', [PaymentsController::class, 'table'])
        ->middleware('can:access-dashboard')
        ->name('payments.table');

    Route::get('/payments/export', [PaymentsController::class, 'exportExcel'])
        ->middleware('can:access-dashboard')
        ->name('payments.export');

    Route::get('/payments/{paymentId}/receipt', [PaymentsController::class, 'receipt'])
        ->middleware('can:access-dashboard')
        ->whereNumber('paymentId')
        ->name('payments.receipt');

    Route::get('/payments/generation-options', [PaymentsController::class, 'generationOptions'])
        ->middleware('can:access-dashboard')
        ->name('payments.generation-options');

    Route::post('/payments/generation-preview', [PaymentsController::class, 'previewGeneration'])
        ->middleware('can:access-dashboard')
        ->name('payments.generation-preview');

    Route::post('/payments/generate', [PaymentsController::class, 'generatePeriods'])
        ->middleware('can:access-dashboard')
        ->name('payments.generate');

    Route::post('/payments', [PaymentsController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('payments.store');

    Route::put('/payments/{paymentId}', [PaymentsController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->whereNumber('paymentId')
        ->name('payments.update');

    Route::post('/memorization-sessions', [MemorizationSessionsController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('memorization-sessions.store');

    Route::put('/memorization-sessions/{sessionId}', [MemorizationSessionsController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->whereNumber('sessionId')
        ->name('memorization-sessions.update');

    Route::post('/attendance', [AttendanceController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('attendance.store');

    Route::post('/attendance/bulk', [AttendanceController::class, 'storeBulk'])
        ->middleware('can:access-dashboard')
        ->name('attendance.bulk');

    Route::post('/attendance/bulk/undo', [AttendanceController::class, 'undoBulk'])
        ->middleware('can:access-dashboard')
        ->name('attendance.bulk.undo');

    Route::delete('/attendance/{attendanceId}', [AttendanceController::class, 'destroy'])
        ->middleware('can:access-dashboard')
        ->whereNumber('attendanceId')
        ->name('attendance.destroy');

    Route::post('/schedules', [SchedulesController::class, 'store'])
        ->middleware('can:access-dashboard')
        ->name('schedules.store');

    Route::put('/schedules/{scheduleId}', [SchedulesController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->whereNumber('scheduleId')
        ->name('schedules.update');

    Route::delete('/schedules/{scheduleId}', [SchedulesController::class, 'destroy'])
        ->middleware('can:access-dashboard')
        ->whereNumber('scheduleId')
        ->name('schedules.destroy');

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

    Route::get('/teacher/dashboard-summary', TeacherDashboardController::class)
        ->middleware('can:access-teacher')
        ->name('teacher.dashboard-summary');

    Route::get('/profile', [ProfileController::class, 'show'])
        ->middleware('can:access-dashboard')
        ->name('profile.show');

    Route::put('/profile', [ProfileController::class, 'update'])
        ->middleware('can:access-dashboard')
        ->name('profile.update');

    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('can:access-dashboard')
        ->name('profile.password.update');

    Route::get('/secretary/dashboard-summary', SecretaryDashboardController::class)
        ->middleware('can:access-secretary')
        ->name('secretary.dashboard-summary');

    Route::get('/admin/users/table', [AdminUsersController::class, 'table'])
        ->middleware('can:access-admin')
        ->name('admin.users.table');

    Route::get('/admin/role-change-history/table', [RoleChangeHistoryController::class, 'table'])
        ->middleware('can:access-admin')
        ->name('admin.role-change-history.table');

    Route::get('/admin/action-history/table', [ActionHistoryController::class, 'table'])
        ->middleware('can:access-admin')
        ->name('admin.action-history.table');

    Route::get('/admin/action-history/export', [ActionHistoryController::class, 'export'])
        ->middleware('can:access-admin')
        ->name('admin.action-history.export');

    Route::get('/admin/action-history/{eventId}', [ActionHistoryController::class, 'show'])
        ->middleware('can:access-admin')
        ->whereNumber('eventId')
        ->name('admin.action-history.show');

    Route::post('/admin/users', [AdminUsersController::class, 'store'])
        ->middleware('can:access-admin')
        ->name('admin.users.store');

    Route::put('/admin/users/{userId}', [AdminUsersController::class, 'update'])
        ->middleware('can:access-admin')
        ->whereNumber('userId')
        ->name('admin.users.update');

    Route::patch('/admin/users/{userId}/status', [AdminUsersController::class, 'updateStatus'])
        ->middleware('can:access-admin')
        ->whereNumber('userId')
        ->name('admin.users.status');

    Route::patch('/admin/users/{userId}/role', [AdminUsersController::class, 'updateRole'])
        ->middleware('can:access-admin')
        ->whereNumber('userId')
        ->name('admin.users.role');

    Route::put('/admin/users/{userId}/access', [AdminUsersController::class, 'resetAccess'])
        ->middleware('can:access-admin')
        ->whereNumber('userId')
        ->name('admin.users.access');

    Route::get('/admin/school-settings', [SchoolSettingsController::class, 'show'])
        ->middleware('can:access-admin')
        ->name('admin.school-settings.show');

    Route::get('/admin/school-settings/receipt-template', [SchoolSettingsController::class, 'receiptTemplate'])
        ->middleware('can:access-admin')
        ->name('admin.school-settings.receipt-template');

    Route::put('/admin/school-settings', [SchoolSettingsController::class, 'update'])
        ->middleware('can:access-admin')
        ->name('admin.school-settings.update');
});
