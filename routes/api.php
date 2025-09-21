<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\UserAuthController;
use App\Http\Controllers\Financial\OrderController;
use App\Http\Controllers\Financial\PaymentController;
use App\Http\Controllers\Store\InventoryController;
use App\Http\Controllers\System\DepartmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// user reqister
Route::post('register', [UserAuthController::class, 'userRegister']);
Route::post('login', [UserAuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // departments
    Route::controller(DepartmentController::class)->prefix('department')->group(function () {
        Route::post('store', 'store');
    });
    // store
    Route::controller(InventoryController::class)->prefix('product')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::get('all', 'allSuppliersProducts');
    });
    // Orders
    Route::controller(OrderController::class)->prefix('order')->group(function () {
        Route::get('index-type', 'indexForTypes');
        Route::get('all-delivered', 'deliveredOrders');
        Route::post('store', 'store');
        Route::post('update-status/{order_id}', 'updateStatus');
    });

    // payments
    Route::controller(PaymentController::class)->prefix('payment')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::post('update/{payment_id}', 'update');
    });
});
// Un Auth
// Index department
Route::get('department/index', [DepartmentController::class, 'index']);
