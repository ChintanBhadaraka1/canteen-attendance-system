<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BulkAttendanceController;
use App\Http\Controllers\CashCheckController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GuestMealController;
use App\Http\Controllers\MealPriceController;
use App\Http\Controllers\MenuPlanController;
use App\Http\Controllers\MenusController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReportController;
use App\Http\Controllers\QuickAttendanceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SpecialPriceController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\StudentLeaveController;
use App\Http\Controllers\UserController;
use App\Models\MealPrice;
use App\Services\MenuCalendar;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Landing', [
    'meals' => MealPrice::offeredOn(today()->toDateString()),
    'menu' => MenuCalendar::forStudents(today()->toDateString(), today()->toDateString())[0]['meals'],
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

    // Before the resource, or "import" is taken as a student ID.
    Route::get('/user/import', [StudentImportController::class, 'index'])->name('user.import');
    Route::post('/user/import', [StudentImportController::class, 'preview'])->name('user.import.preview');
    Route::delete('/user/import', [StudentImportController::class, 'discard'])->name('user.import.discard');
    Route::get('/user/import/template', [StudentImportController::class, 'template'])->name('user.import.template');
    Route::post('/user/import/confirm', [StudentImportController::class, 'confirm'])->name('user.import.confirm');
    Route::patch('/user/{user}/status', [UserController::class, 'status'])->name('user.status');
    Route::post('/user/{user}/leave', [StudentLeaveController::class, 'store'])->name('user.leave.store');
    Route::delete('/user/leave/{leave}', [StudentLeaveController::class, 'destroy'])->name('user.leave.destroy');

    Route::resource('/user', UserController::class);

    Route::resource('/menus', MenusController::class)->except('show');

    Route::get('/menu-plan', [MenuPlanController::class, 'index'])->name('menu-plan.index');
    Route::get('/menu-plan/edit', [MenuPlanController::class, 'edit'])->name('menu-plan.edit');
    Route::post('/menu-plan', [MenuPlanController::class, 'store'])->name('menu-plan.store');
    Route::post('/menu-plan/copy', [MenuPlanController::class, 'copy'])->name('menu-plan.copy');
    Route::post('/menu-plan/{plan}/stop', [MenuPlanController::class, 'stop'])->name('menu-plan.stop');

    Route::resource('/meal-price', MealPriceController::class)->except('show');
    Route::post('/meal-price/{meal}/move', [MealPriceController::class, 'move'])->name('meal-price.move');
    Route::post('/meal-price/{meal}/special', [SpecialPriceController::class, 'store'])->name('special-price.store');
    Route::put('/special-price/{special}', [SpecialPriceController::class, 'update'])->name('special-price.update');
    Route::delete('/special-price/{special}', [SpecialPriceController::class, 'destroy'])->name('special-price.destroy');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/modules', [SettingsController::class, 'modules'])->name('settings.modules');
    Route::post('/settings/options', [SettingsController::class, 'store'])->name('settings.options.store');
    Route::put('/settings/options/{option}', [SettingsController::class, 'update'])->name('settings.options.update');
    Route::delete('/settings/options/{option}', [SettingsController::class, 'destroy'])->name('settings.options.destroy');
    Route::post('/settings/options/{option}/move', [SettingsController::class, 'move'])->name('settings.options.move');

    Route::get('/student-attendance', [StudentAttendanceController::class, 'index'])->name('student-attendance.index');
    Route::get('/student-attendance/bulk', [BulkAttendanceController::class, 'index'])->name('attendance.bulk');
    Route::post('/student-attendance/bulk', [BulkAttendanceController::class, 'store'])->name('attendance.bulk.store');
    Route::post('/student-attendance/bulk/{batch}/undo', [BulkAttendanceController::class, 'undo'])->name('attendance.bulk.undo');
    Route::get('/student-attendance/quick', [QuickAttendanceController::class, 'index'])->name('attendance.quick');
    Route::post('/student-attendance/quick', [QuickAttendanceController::class, 'store'])->name('attendance.quick.store');
    Route::get('/student-attendance/guest', [GuestMealController::class, 'index'])->name('guest.index');
    Route::post('/student-attendance/guest/card', [GuestMealController::class, 'card'])->name('guest.card');
    Route::post('/student-attendance/guest/walk-in', [GuestMealController::class, 'walkIn'])->name('guest.walk-in');
    Route::post('/student-attendance/guest/{guest}/cancel', [GuestMealController::class, 'cancel'])->name('guest.cancel');
    Route::get('/student-attendance/create/{id}', [StudentAttendanceController::class, 'create'])->name('student-attendance.create');
    Route::post('/student-attendance/store', [StudentAttendanceController::class, 'store'])->name('student-attendance.store');

    Route::get('/student-attendance/show/{id}', [StudentAttendanceController::class, 'show'])->name('student-attendance.show');
    Route::get('/student-attendance/download', [StudentAttendanceController::class, 'download'])->name('student-attendance.download');
    Route::get('/student-attendance/user/download', [StudentAttendanceController::class, 'downloadUserHistory'])->name('student-attendance.user-download');

    Route::post('/student-attendance/delete', [StudentAttendanceController::class, 'deleteSpecificAttedance'])->name('student-attendance.delete-attendance');


    // Before the resource, or "statement" is taken as a bill ID.
    Route::get('/bill/statement/{student}', [StatementController::class, 'one'])->name('bill.statement');
    Route::resource('/bill', PaymentController::class)->only('index', 'edit', 'update');
    Route::get('/bill/{history}/receipt', [PaymentController::class, 'downloadReceipt'])->name('bill.receipt');
    Route::post('/bill/{history}/cancel', [PaymentController::class, 'cancel'])->name('bill.cancel');

    Route::get('/stock', [StockItemController::class, 'index'])->name('stock.index');
    Route::post('/stock', [StockItemController::class, 'store'])->name('stock.store');
    Route::get('/stock/create', [StockItemController::class, 'create'])->name('stock.create');
    Route::get('/stock/shopping-list', [StockItemController::class, 'shoppingList'])->name('stock.shopping-list');
    Route::get('/stock/use', [StockEntryController::class, 'useForm'])->name('stock.use');
    Route::post('/stock/use', [StockEntryController::class, 'use'])->name('stock.use.store');
    Route::get('/stock/waste', [StockEntryController::class, 'wasteForm'])->name('stock.waste');
    Route::post('/stock/waste', [StockEntryController::class, 'waste'])->name('stock.waste.store');
    Route::get('/stock/count', [StockEntryController::class, 'countForm'])->name('stock.count');
    Route::post('/stock/count', [StockEntryController::class, 'count'])->name('stock.count.store');
    Route::post('/stock/entries/{entry}/cancel', [StockEntryController::class, 'cancel'])->name('stock.entries.cancel');
    Route::patch('/stock/packs/{pack}/active', [StockItemController::class, 'packActive'])->name('stock.packs.active');
    Route::get('/stock/{item}', [StockItemController::class, 'show'])->name('stock.show');
    Route::put('/stock/{item}', [StockItemController::class, 'update'])->name('stock.update');
    Route::get('/stock/{item}/edit', [StockItemController::class, 'edit'])->name('stock.edit');
    Route::patch('/stock/{item}/active', [StockItemController::class, 'active'])->name('stock.active');
    Route::post('/stock/{item}/packs', [StockItemController::class, 'addPack'])->name('stock.packs.store');

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::get('/purchases/report', [PurchaseReportController::class, 'index'])->name('purchases.report');
    Route::get('/purchases/report/download', [PurchaseReportController::class, 'download'])->name('purchases.report.download');
    Route::get('/purchases/{entry}', [PurchaseController::class, 'show'])->name('purchases.show');
    Route::get('/purchases/{entry}/bill', [PurchaseController::class, 'bill'])->name('purchases.bill');

    Route::middleware('module:expenses')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::get('/expenses/download', [ExpenseController::class, 'download'])->name('expenses.download');
        Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
    });

    Route::middleware('module:staff')->group(function () {
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
        Route::post('/staff/entries/{entry}/cancel', [SalaryController::class, 'cancel'])->name('staff.entries.cancel');
        Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
        Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
        Route::patch('/staff/{staff}/status', [StaffController::class, 'status'])->name('staff.status');
        Route::post('/staff/{staff}/rates', [SalaryController::class, 'rate'])->name('staff.rates.store');
        Route::post('/staff/{staff}/entries', [SalaryController::class, 'store'])->name('staff.entries.store');
    });

    Route::middleware('module:cash')->group(function () {
        Route::get('/cash-check', [CashCheckController::class, 'index'])->name('cash.index');
        Route::post('/cash-check', [CashCheckController::class, 'store'])->name('cash.store');
        Route::post('/cash-check/{check}/cancel', [CashCheckController::class, 'cancel'])->name('cash.cancel');
    });

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
    Route::get('/reports/summary/download', [ReportController::class, 'summaryDownload'])->name('reports.summary.download');
    Route::get('/reports/due', [ReportController::class, 'due'])->name('reports.due');
    Route::get('/reports/due/download', [ReportController::class, 'dueDownload'])->name('reports.due.download');
    Route::get('/reports/meals', [ReportController::class, 'meals'])->name('reports.meals');
    Route::get('/reports/meals/download', [ReportController::class, 'mealsDownload'])->name('reports.meals.download');
    Route::get('/reports/statements', [StatementController::class, 'index'])->name('reports.statements');
    Route::get('/reports/statements/download', [StatementController::class, 'download'])->name('reports.statements.download');

    Route::get('/changes', [ActivityController::class, 'index'])->name('changes.index');
});
