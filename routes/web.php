<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\VariantLookupController;
use App\Http\Controllers\Master\CourierController;
use App\Http\Controllers\Master\LocationController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\RackController;
use App\Http\Controllers\Master\UnitController;
use App\Http\Controllers\Master\WarehouseController;
use App\Http\Controllers\Master\ZoneController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Tidak ada route register: akun hanya dibuat lewat admin panel.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::prefix('master')->name('master.')->group(function () {
        // Zona, rak & lokasi bin memakai permission yang sama dengan gudang;
        // satuan memakai permission produk.
        $resources = [
            'warehouses' => [WarehouseController::class, 'warehouses', 'warehouse'],
            'zones' => [ZoneController::class, 'warehouses', 'zone'],
            'racks' => [RackController::class, 'warehouses', 'rack'],
            'locations' => [LocationController::class, 'warehouses', 'location'],
            'couriers' => [CourierController::class, 'couriers', 'courier'],
            'products' => [ProductController::class, 'products', 'product'],
            'units' => [UnitController::class, 'products', 'unit'],
        ];

        foreach ($resources as $uri => [$controller, $permission, $param]) {
            Route::get($uri, [$controller, 'index'])->middleware("can:{$permission}.view")->name("{$uri}.index");
            Route::get("{$uri}/create", [$controller, 'create'])->middleware("can:{$permission}.create")->name("{$uri}.create");
            Route::post($uri, [$controller, 'store'])->middleware("can:{$permission}.create")->name("{$uri}.store");
            Route::get("{$uri}/{{$param}}/edit", [$controller, 'edit'])->middleware("can:{$permission}.edit")->name("{$uri}.edit");
            Route::put("{$uri}/{{$param}}", [$controller, 'update'])->middleware("can:{$permission}.edit")->name("{$uri}.update");
            Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->middleware("can:{$permission}.delete")->name("{$uri}.destroy");
        }
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('stocks', [StockController::class, 'index'])->middleware('can:inventory.view')->name('stocks.index');
        Route::get('stock-card', [StockCardController::class, 'index'])->middleware('can:inventory.view')->name('stock-card.index');
        Route::get('lookup/variants', VariantLookupController::class)->middleware('can:inventory.view')->name('lookup.variants');

        // Hak buat mutasi dicek di controller: penyesuaian butuh inventory.adjust,
        // jenis lain inventory.create. Mutasi tidak bisa diubah/dihapus.
        Route::get('movements', [StockMovementController::class, 'index'])->middleware('can:inventory.view')->name('movements.index');
        Route::get('movements/create', [StockMovementController::class, 'create'])->name('movements.create');
        Route::post('movements', [StockMovementController::class, 'store'])->name('movements.store');
        Route::get('movements/{movement}', [StockMovementController::class, 'show'])->middleware('can:inventory.view')->name('movements.show');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.edit')->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.edit')->name('users.update');
        Route::patch('users/{user}/status', [UserController::class, 'toggleStatus'])->middleware('can:users.edit')->name('users.status');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');

        Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:roles.create')->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.edit')->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.edit')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');
    });
});
