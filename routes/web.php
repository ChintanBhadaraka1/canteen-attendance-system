<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MealPriceController;
use App\Http\Controllers\MenusController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\UserController;
use App\Models\MealPrice;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Landing', [
    'meals' => MealPrice::get(['id', 'name', 'price']),
]))->name('home');


Route::middleware(['guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'index'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.user');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/account', [AuthController::class, 'account'])->middleware('auth')->name('account');


Route::middleware(['auth', 'admin', 'cache.headers:no_store'])->group(function () {


    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('/user', UserController::class);

    Route::resource('/menus', MenusController::class)->except('show');

    Route::resource('/meal-price', MealPriceController::class)->except('show');

    Route::get('/student-attendance', [StudentAttendanceController::class, 'index'])->name('student-attendance.index');
    Route::get('/student-attendance/create/{id}', [StudentAttendanceController::class, 'create'])->name('student-attendance.create');
    Route::post('/student-attendance/store', [StudentAttendanceController::class, 'store'])->name('student-attendance.store');

    Route::get('/student-attendance/show/{id}', [StudentAttendanceController::class, 'show'])->name('student-attendance.show');
    Route::get('/student-attendance/download', [StudentAttendanceController::class, 'download'])->name('student-attendance.download');
    Route::get('/student-attendance/user/download', [StudentAttendanceController::class, 'downloadUserHistory'])->name('student-attendance.user-download');

    Route::post('/student-attendance/delete', [StudentAttendanceController::class, 'deleteSpecificAttedance'])->name('student-attendance.delete-attendance');


    Route::resource('/bill', PaymentController::class)->only('index', 'edit', 'update');
    Route::get('/bill/{history}/receipt', [PaymentController::class, 'downloadReceipt'])->name('bill.receipt');
});
