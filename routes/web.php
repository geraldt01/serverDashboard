<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringIngestController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WordpressSiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/login', fn () => view('auth.login'))->middleware('guest')->name('login');
Route::post('/login', [DashboardController::class, 'login'])->middleware(['guest', 'throttle:5,1'])->name('login.store');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [DashboardController::class, 'logout'])->name('logout');
    Route::post('/monitor/ec2/sync', [MonitoringIngestController::class, 'syncEc2'])->middleware('role:admin')->name('monitor.ec2.sync');
    Route::get('/users', [UserManagementController::class, 'index'])->middleware('role:admin')->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->middleware('role:admin')->name('users.store');
    Route::get('/wordpress-sites', [WordpressSiteController::class, 'index'])->middleware('role:admin')->name('wordpress-sites.index');
    Route::post('/wordpress-sites', [WordpressSiteController::class, 'store'])->middleware('role:admin')->name('wordpress-sites.store');
    Route::post('/wordpress-sites/{wordpressSite}/rotate-token', [WordpressSiteController::class, 'rotateToken'])->middleware('role:admin')->name('wordpress-sites.rotate-token');
    Route::post('/wordpress-sites/{wordpressSite}/toggle-active', [WordpressSiteController::class, 'toggleActive'])->middleware('role:admin')->name('wordpress-sites.toggle-active');
});

Route::post('/ingest/wordpress/site/{wordpressSite:slug}', [WordpressSiteController::class, 'report'])
    ->middleware(['wordpress.site.token', 'throttle:120,1'])
    ->name('wordpress-sites.report');

Route::post('/ingest/wordpress/site/{wordpressSite:slug}/login', [WordpressSiteController::class, 'reportLogin'])
    ->middleware(['wordpress.site.token', 'throttle:120,1'])
    ->name('wordpress-sites.report-login');

Route::prefix('ingest')->middleware(['monitor.key', 'throttle:120,1'])->group(function () {
    Route::post('/traffic', [MonitoringIngestController::class, 'traffic']);
    Route::post('/wordpress', [MonitoringIngestController::class, 'wordpress']);
});
