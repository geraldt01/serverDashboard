<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringIngestController;
use App\Http\Controllers\OtherServerController;
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
    Route::put('/users/{user}', [UserManagementController::class, 'update'])->middleware('role:admin')->name('users.update');
    Route::get('/wordpress-sites', [WordpressSiteController::class, 'index'])->middleware('role:admin')->name('wordpress-sites.index');
    Route::post('/wordpress-sites', [WordpressSiteController::class, 'store'])->middleware('role:admin')->name('wordpress-sites.store');
    Route::post('/wordpress-sites/{wordpressSite}/rotate-token', [WordpressSiteController::class, 'rotateToken'])->middleware('role:admin')->name('wordpress-sites.rotate-token');
    Route::post('/wordpress-sites/{wordpressSite}/toggle-active', [WordpressSiteController::class, 'toggleActive'])->middleware('role:admin')->name('wordpress-sites.toggle-active');
    Route::post('/wordpress-sites/{wordpressSite}/whitelist', [WordpressSiteController::class, 'updateWhitelist'])->middleware('role:admin')->name('wordpress-sites.update-whitelist');
    Route::get('/other-servers', [OtherServerController::class, 'index'])->middleware('role:admin')->name('other-servers.index');
    Route::post('/other-servers', [OtherServerController::class, 'store'])->middleware('role:admin')->name('other-servers.store');
    Route::post('/other-servers/{otherServer}/rotate-token', [OtherServerController::class, 'rotateToken'])->middleware('role:admin')->name('other-servers.rotate-token');
    Route::post('/other-servers/{otherServer}/toggle-active', [OtherServerController::class, 'toggleActive'])->middleware('role:admin')->name('other-servers.toggle-active');
    Route::post('/other-servers/{otherServer}/test-connection', [OtherServerController::class, 'testConnection'])->middleware(['role:admin', 'throttle:10,1'])->name('other-servers.test-connection');
    Route::post('/other-servers/{otherServer}/patch-now', [OtherServerController::class, 'patchNow'])->middleware(['role:admin', 'throttle:10,1'])->name('other-servers.patch-now');
});

Route::post('/ingest/wordpress/site/{wordpressSite:slug}', [WordpressSiteController::class, 'report'])
    ->middleware(['wordpress.site.token', 'throttle:120,1'])
    ->name('wordpress-sites.report');

Route::post('/ingest/wordpress/site/{wordpressSite:slug}/login', [WordpressSiteController::class, 'reportLogin'])
    ->middleware(['wordpress.site.token', 'throttle:120,1'])
    ->name('wordpress-sites.report-login');

Route::post('/ingest/other-server/{otherServer:slug}/report', [OtherServerController::class, 'report'])
    ->middleware(['other.server.token', 'throttle:60,1'])
    ->name('other-servers.report');

Route::prefix('ingest')->middleware(['monitor.key', 'throttle:120,1'])->group(function () {
    Route::post('/traffic', [MonitoringIngestController::class, 'traffic']);
    Route::post('/wordpress', [MonitoringIngestController::class, 'wordpress']);
});
