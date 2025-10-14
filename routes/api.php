<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\UserAuthController;
use App\Http\Controllers\Clinic\ProductController;
use App\Http\Controllers\Financial\CartController;
use App\Http\Controllers\Financial\OrderController;
use App\Http\Controllers\Financial\PackageController;
use App\Http\Controllers\Financial\PaymentController;
use App\Http\Controllers\Financial\ReceiptController;
use App\Http\Controllers\Report\DoctorController;
use App\Http\Controllers\Report\SupplierController;
use App\Http\Controllers\Shopping\FavoriteProductController;
use App\Http\Controllers\Store\InventoryController;
use App\Http\Controllers\System\CategoryController;
use App\Http\Controllers\System\CityController;
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
    // Category
    Route::controller(CategoryController::class)->prefix('category')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::get('show/{id}', 'show');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
    });
    // cart
    Route::controller(CartController::class)->prefix('cart')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
    });
    // favorite
    Route::controller(FavoriteProductController::class)->prefix('favorite-product')->group(function () {
        Route::get('index', 'index');
        Route::get('add/{product_id}', 'addToFavorite');
        Route::delete('remove/{product_id}', 'removeFromFavorite');
    });
    // store
    Route::controller(InventoryController::class)->prefix('product')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
        Route::post('multi-delete', 'multiDestroy');
        Route::get('all', 'allSuppliersProducts');
        Route::get('search', 'search');
        Route::get('export', 'exportExcel');
    });
    // Orders
    Route::controller(OrderController::class)->prefix('order')->group(function () {
        Route::get('index-type', 'indexForTypes');
        Route::get('all-delivered', 'deliveredOrders');
        Route::post('store', 'store');
        Route::post('update-status/{order_id}', 'updateStatus');
        Route::post('update/{package}', 'update');
        Route::post('update-item/{orderItem_id}', 'UpdateItem');
        Route::delete('delete/{id}', 'destroy');
        Route::delete('delete-item/{orderItem_id}', 'deleteItem');
        Route::post('items/{orderItemId}/return', 'returnItem');
        Route::get('delivered/export', 'exportDeliveredOrders');
        Route::get('search', 'searchOrders');
        //Route::get('showexpen', 'showexpen');
    });
    // payments
    Route::controller(PaymentController::class)->prefix('payment')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::post('update/{payment_id}', 'update');
        Route::post('confirm/{payment_id}', 'updatePaymentStatus');
        Route::get('pending', 'pendingPyments');
        Route::get('delete/{payment_id}', 'deleteRequest');
        Route::get('export', 'exportToExcel');
        Route::get('search', 'search');
    });
    // receipts
    Route::controller(ReceiptController::class)->prefix('receipt')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'store');
        Route::get('show/{id}', 'show');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
        Route::post('deleteByDate', 'destroyByDate');
    });
    // packages
    Route::controller(PackageController::class)->prefix('package')->group(function () {
        Route::get('index', 'index');
        Route::post('store', 'createPackage');
        Route::post('buy/{packageId}', 'buyPackage');
        Route::get('show/{package_id}', 'show');
        Route::get('remain-products/{packageId}', 'remainingProducts');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
        Route::get('toggle-status/{id}', 'toggleStatus');
    });
    // reports
    Route::prefix('report')->group(function () {
        // المورد
        Route::controller(SupplierController::class)->prefix('doctor')->group(function () {
            Route::get('all', 'getAllDoctors');
            Route::get('{doctor_id}/details', 'showDoctorDetails');
        });
        // الطبيب
        Route::controller(DoctorController::class)->prefix('supplier')->group(function () {
            Route::get('all', 'getAllsuppliers');
            Route::get('{doctor_id}/details', 'showDoctorDetails');
        });
    });
    // city
    Route::controller(CityController::class)->prefix('city')->group(function () {
        Route::post('store', 'store');
        Route::get('show/{id}', 'show');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
    });
    // clinic
    Route::prefix('clinic')->group(function () {
        Route::controller(ProductController::class)->prefix('product')->group(function () {
            Route::get('search', 'search');
        });
    });
});
// Un Auth
// Index department
Route::get('department/index', [DepartmentController::class, 'index']);
Route::get('city/index', [CityController::class, 'index']);
Route::get('database/backup', [OrderController::class, 'backupDatabase']);
