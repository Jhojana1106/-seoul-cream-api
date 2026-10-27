<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogAdminController;
use App\Http\Controllers\Api\V1\CommerceAdminController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StorefrontController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);

        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::post('avatar', [AuthController::class, 'updateAvatar']);
            Route::delete('avatar', [AuthController::class, 'removeAvatar']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });
    });

    Route::prefix('store')->group(function () {
        Route::get('settings', [StorefrontController::class, 'settings'])->name('store.settings');
        Route::get('categories', [StorefrontController::class, 'categories'])->name('store.categories');
        Route::get('products', [StorefrontController::class, 'products'])->name('store.products');
        Route::get('products/{slug}', [StorefrontController::class, 'product'])->name('store.products.show');
        Route::get('toppings', [StorefrontController::class, 'toppings'])->name('store.toppings');
        Route::get('payment-methods', [StorefrontController::class, 'paymentMethods'])->name('store.payment-methods');
        Route::post('quote', [StorefrontController::class, 'quote'])->name('store.quote');
        Route::post('checkout', [StorefrontController::class, 'checkout'])->middleware('auth.optional')->name('store.checkout');
        Route::get('orders/{number}', [StorefrontController::class, 'showOrder'])->middleware('auth.optional')->name('store.orders.show');

        Route::middleware('auth:api')->group(function () {
            Route::get('orders', [StorefrontController::class, 'myOrders'])->name('store.orders');
            Route::post('orders/{number}/cancel', [StorefrontController::class, 'cancelOrder']);
            Route::get('addresses', [StorefrontController::class, 'addresses']);
            Route::post('addresses', [StorefrontController::class, 'storeAddress']);
            Route::put('addresses/{address}', [StorefrontController::class, 'updateAddress']);
            Route::delete('addresses/{address}', [StorefrontController::class, 'destroyAddress']);
        });
    });

    Route::middleware('auth:api')->group(function () {

        Route::get('permissions/catalog', [PermissionController::class, 'catalog'])
            ->middleware('permission:permissions.view|roles.assign-permissions');
        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.create');
        Route::get('permissions/{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
        Route::put('permissions/{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.update');
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.delete');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
        Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.assign-permissions');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign-roles');

        Route::get('categories', [CatalogAdminController::class, 'categories'])->middleware('permission:categories.view');
        Route::post('categories', [CatalogAdminController::class, 'storeCategory'])->middleware('permission:categories.create');
        Route::put('categories/{category}', [CatalogAdminController::class, 'updateCategory'])->middleware('permission:categories.update');
        Route::delete('categories/{category}', [CatalogAdminController::class, 'destroyCategory'])->middleware('permission:categories.delete');

        Route::get('products', [CatalogAdminController::class, 'products'])->middleware('permission:products.view');
        Route::post('products', [CatalogAdminController::class, 'storeProduct'])->middleware('permission:products.create');
        Route::get('products/{product}', [CatalogAdminController::class, 'showProduct'])->middleware('permission:products.view');
        Route::put('products/{product}', [CatalogAdminController::class, 'updateProduct'])->middleware('permission:products.update');
        Route::delete('products/{product}', [CatalogAdminController::class, 'destroyProduct'])->middleware('permission:products.delete');
        Route::post('products/{product}/image', [CatalogAdminController::class, 'storeImage'])->middleware('permission:products.update');
        Route::delete('products/{product}/image', [CatalogAdminController::class, 'destroyImage'])->middleware('permission:products.update');
        Route::post('products/examples', [CatalogAdminController::class, 'examples'])->middleware('permission:products.create');

        Route::get('toppings', [CatalogAdminController::class, 'toppings'])->middleware('permission:toppings.view');
        Route::post('toppings', [CatalogAdminController::class, 'storeTopping'])->middleware('permission:toppings.create');
        Route::put('toppings/{topping}', [CatalogAdminController::class, 'updateTopping'])->middleware('permission:toppings.update');
        Route::delete('toppings/{topping}', [CatalogAdminController::class, 'destroyTopping'])->middleware('permission:toppings.delete');

        Route::get('allergens', [CatalogAdminController::class, 'allergens'])->middleware('permission:products.view');
        Route::post('allergens', [CatalogAdminController::class, 'storeAllergen'])->middleware('permission:products.create');
        Route::delete('allergens/{allergen}', [CatalogAdminController::class, 'destroyAllergen'])->middleware('permission:products.delete');

        Route::get('units', [OperationsController::class, 'units'])->middleware('permission:ingredients.view|inventory.view');
        Route::get('ingredients', [OperationsController::class, 'ingredients'])->middleware('permission:ingredients.view');
        Route::post('ingredients', [OperationsController::class, 'storeIngredient'])->middleware('permission:ingredients.create');
        Route::put('ingredients/{ingredient}', [OperationsController::class, 'updateIngredient'])->middleware('permission:ingredients.update');
        Route::delete('ingredients/{ingredient}', [OperationsController::class, 'destroyIngredient'])->middleware('permission:ingredients.delete');
        Route::get('inventory/movements', [OperationsController::class, 'kardex'])->middleware('permission:inventory.view');
        Route::get('inventory/lots', [OperationsController::class, 'lots'])->middleware('permission:inventory.view');
        Route::post('inventory/movements', [OperationsController::class, 'movement'])->middleware('permission:inventory.move');
        Route::post('inventory/waste', [OperationsController::class, 'waste'])->middleware('permission:inventory.move');
        Route::post('inventory/transfers', [OperationsController::class, 'transfer'])->middleware('permission:inventory.move');

        Route::get('display-cases', [OperationsController::class, 'cases'])->middleware('permission:display-cases.view');
        Route::post('display-cases', [OperationsController::class, 'storeCase'])->middleware('permission:display-cases.create');
        Route::get('display-cases/{displayCase}', [OperationsController::class, 'showCase'])->middleware('permission:display-cases.view');
        Route::put('display-cases/{displayCase}', [OperationsController::class, 'updateCase'])->middleware('permission:display-cases.update');
        Route::delete('display-cases/{displayCase}', [OperationsController::class, 'destroyCase'])->middleware('permission:display-cases.delete');
        Route::post('display-cases/{displayCase}/temperature', [OperationsController::class, 'temperature'])->middleware('permission:display-cases.update');
        Route::post('display-cases/{displayCase}/events', [OperationsController::class, 'caseEvent'])->middleware('permission:display-cases.update');
        Route::post('temperature-alerts/{alert}/acknowledge', [OperationsController::class, 'acknowledgeAlert'])->middleware('permission:display-cases.update');

        Route::get('recipes', [OperationsController::class, 'recipes'])->middleware('permission:recipes.view');
        Route::post('recipes', [OperationsController::class, 'storeRecipe'])->middleware('permission:recipes.create');
        Route::put('recipes/{recipe}', [OperationsController::class, 'updateRecipe'])->middleware('permission:recipes.update');
        Route::delete('recipes/{recipe}', [OperationsController::class, 'destroyRecipe'])->middleware('permission:recipes.delete');
        Route::post('recipes/{recipe}/produce', [OperationsController::class, 'produce'])->middleware('permission:production.create');
        Route::get('production-batches', [OperationsController::class, 'batches'])->middleware('permission:production.view');

        Route::get('suppliers', [OperationsController::class, 'suppliers'])->middleware('permission:suppliers.view');
        Route::post('suppliers', [OperationsController::class, 'storeSupplier'])->middleware('permission:suppliers.create');
        Route::put('suppliers/{supplier}', [OperationsController::class, 'updateSupplier'])->middleware('permission:suppliers.update');
        Route::delete('suppliers/{supplier}', [OperationsController::class, 'destroySupplier'])->middleware('permission:suppliers.delete');
        Route::get('purchase-orders', [OperationsController::class, 'purchaseOrders'])->middleware('permission:purchases.view');
        Route::post('purchase-orders', [OperationsController::class, 'storePurchase'])->middleware('permission:purchases.create');
        Route::post('purchase-orders/{purchaseOrder}/order', [OperationsController::class, 'orderPurchase'])->middleware('permission:purchases.create');
        Route::post('purchase-orders/{purchaseOrder}/receive', [OperationsController::class, 'receivePurchase'])->middleware('permission:purchases.receive');

        Route::get('orders', [CommerceAdminController::class, 'orders'])->middleware('permission:orders.view');
        Route::get('orders/{number}', [CommerceAdminController::class, 'showOrder'])->middleware('permission:orders.view');
        Route::post('orders/{order}/status', [CommerceAdminController::class, 'transition'])->middleware('permission:orders.update|orders.cancel');
        Route::post('orders/{order}/payment', [CommerceAdminController::class, 'payment'])->middleware('permission:orders.update');
        Route::post('orders/{order}/refund', [CommerceAdminController::class, 'refund'])->middleware('permission:pos.refund|orders.cancel');

        Route::get('pos/payment-methods', [CommerceAdminController::class, 'posPaymentMethods'])->middleware('permission:pos.access');
        Route::get('cash-sessions/current', [CommerceAdminController::class, 'currentCash'])->middleware('permission:cash.manage|pos.access');
        Route::post('cash-sessions', [CommerceAdminController::class, 'openCash'])->middleware('permission:cash.manage');
        Route::post('cash-sessions/{cashSession}/close', [CommerceAdminController::class, 'closeCash'])->middleware('permission:cash.manage');
        Route::post('pos/sales', [CommerceAdminController::class, 'sell'])->middleware('permission:pos.access');

        Route::get('customers', [CommerceAdminController::class, 'customers'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/orders', [CommerceAdminController::class, 'customerOrders'])->middleware('permission:customers.view');

        Route::get('reports/dashboard', [CommerceAdminController::class, 'dashboard'])->middleware('permission:dashboard.view');
        Route::get('reports/sales', [CommerceAdminController::class, 'salesReport'])->middleware('permission:reports.view');
        Route::get('reports/inventory', [CommerceAdminController::class, 'inventoryReport'])->middleware('permission:reports.view');
        Route::get('reports/sales.csv', [CommerceAdminController::class, 'exportSales'])->middleware('permission:reports.view');

        Route::get('settings', [CommerceAdminController::class, 'showSettings'])->middleware('permission:settings.view');
        Route::put('settings', [CommerceAdminController::class, 'updateSettings'])->middleware('permission:settings.update');
        Route::get('coupons', [CommerceAdminController::class, 'coupons'])->middleware('permission:coupons.view');
        Route::post('coupons', [CommerceAdminController::class, 'storeCoupon'])->middleware('permission:coupons.manage');
        Route::put('coupons/{coupon}', [CommerceAdminController::class, 'updateCoupon'])->middleware('permission:coupons.manage');
        Route::get('payment-methods', [CommerceAdminController::class, 'paymentMethods'])->middleware('permission:settings.view');
        Route::put('payment-methods/{paymentMethod}', [CommerceAdminController::class, 'updatePaymentMethod'])->middleware('permission:settings.update');
    });
});
