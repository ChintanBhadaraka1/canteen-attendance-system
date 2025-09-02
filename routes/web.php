<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MealPriceController;
use App\Http\Controllers\MenusController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentHistoryController;
use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\UserController;

Route::get('/', function () {
    return redirect()->route('login');
});


Route::get('/login', [AuthController::class, 'index'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.user');
Route::get('/logout', [AuthController::class, 'logout'])->name('logout');


Route::middleware(['auth'])->group(function () {


    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('/user', UserController::class);
    Route::post('/user/list', [UserController::class, 'list'])->name('user.list');

    Route::resource('/menus', MenusController::class);
    Route::post('/menus/list', [MenusController::class, 'list'])->name('menus.list');

    Route::resource('/meal-price', MealPriceController::class);

    Route::get('/student-attendance', [StudentAttendanceController::class, 'index'])->name('student-attendance.index');
    Route::get('/student-attendance/create/{id}', [StudentAttendanceController::class, 'create'])->name('student-attendance.create');
    Route::post('/student-attendance/store', [StudentAttendanceController::class, 'store'])->name('student-attendance.store');
    Route::post('/student-attendance/list', [StudentAttendanceController::class, 'list'])->name('student-attendance.list');
    Route::post('/student-attendance/specific-user/list', [StudentAttendanceController::class, 'specificUserList'])->name('student-attendance.specific-user');

    Route::get('/student-attendance/show/{id}', [StudentAttendanceController::class, 'show'])->name('student-attendance.show');
    Route::post('/student-attendance/download', [StudentAttendanceController::class, 'download'])->name('student-attendance.download');
    Route::post('/student-attendance/user/download', [StudentAttendanceController::class, 'downloadUserHistory'])->name('student-attendance.user-download');

    Route::post('/student-attendance/direct', [StudentAttendanceController::class, 'addTodayAttedance'])->name('student-attendance.today-attendance');
    Route::post('/student-attendance/delete', [StudentAttendanceController::class, 'deleteSpecificAttedance'])->name('student-attendance.delete-attendance');


    Route::resource('/bill',PaymentController::class);
    Route::post('/bill/list', [PaymentController::class, 'list'])->name('bill.list');
    // Route::get('/bills/download', [PaymentController::class, 'downloadReceipt'])->name('bill.download');
    Route::post('/bill/download', [PaymentController::class, 'downloadReceipt'])->name('bill.download');


    // route::get('/sample',[PaymentHistoryController::class,'index']);

    

});
