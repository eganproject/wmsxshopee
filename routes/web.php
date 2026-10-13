<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\OpeningStockImportController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockCountController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\VariantLookupController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\Master\BundleController;
use App\Http\Controllers\Master\CourierController;
use App\Http\Controllers\Master\LocationController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\ProductImportController;
use App\Http\Controllers\Master\RackController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Master\UnitController;
use App\Http\Controllers\Master\WarehouseController;
use App\Http\Controllers\Master\ZoneController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesOrderImportController;
use App\Http\Controllers\Sales\SalesReturnController;
use App\Http\Controllers\Shopee\ShopeeSettingController;
use App\Http\Controllers\Shopee\ShopeeShopController;
use App\Http\Controllers\Shopee\ShopeeSkuMappingController;
use App\Http\Controllers\Shopee\ShopeeWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Push Mechanism Shopee (tanpa login; diverifikasi lewat tanda tangan partner key).
Route::post('shopee/webhook', ShopeeWebhookController::class)->name('shopee.webhook');

// Tidak ada route register: akun hanya dibuat lewat admin panel.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('notifications', NotificationController::class)->name('notifications.index');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::prefix('master')->name('master.')->group(function () {
        // Impor massal produk dari Excel/CSV.
        Route::get('products/import', [ProductImportController::class, 'create'])->middleware('can:products.create')->name('products.import');
        Route::post('products/import', [ProductImportController::class, 'store'])->middleware('can:products.create')->name('products.import.store');
        Route::get('products/import/template.{format}', [ProductImportController::class, 'template'])->middleware('can:products.create')->name('products.import.template');

        // Zona, rak & lokasi bin memakai permission yang sama dengan gudang;
        // satuan & produk paket memakai permission produk.
        $resources = [
            'warehouses' => [WarehouseController::class, 'warehouses', 'warehouse'],
            'zones' => [ZoneController::class, 'warehouses', 'zone'],
            'racks' => [RackController::class, 'warehouses', 'rack'],
            'locations' => [LocationController::class, 'warehouses', 'location'],
            'couriers' => [CourierController::class, 'couriers', 'courier'],
            'suppliers' => [SupplierController::class, 'suppliers', 'supplier'],
            'products' => [ProductController::class, 'products', 'product'],
            'units' => [UnitController::class, 'products', 'unit'],
            'bundles' => [BundleController::class, 'products', 'bundle'],
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
        Route::get('opening-stock', [OpeningStockImportController::class, 'create'])->middleware('can:inventory.create')->name('opening-stock.create');
        Route::post('opening-stock', [OpeningStockImportController::class, 'store'])->middleware('can:inventory.create')->name('opening-stock.store');
        Route::get('opening-stock/template.{format}', [OpeningStockImportController::class, 'template'])->middleware('can:inventory.create')->name('opening-stock.template');
        Route::get('stock-card', [StockCardController::class, 'index'])->middleware('can:inventory.view')->name('stock-card.index');
        Route::get('lookup/variants', VariantLookupController::class)->middleware('can:inventory.view')->name('lookup.variants');

        // Hak buat mutasi dicek di controller: penyesuaian butuh inventory.adjust,
        // jenis lain inventory.create. Mutasi tidak bisa diubah/dihapus.
        Route::get('movements', [StockMovementController::class, 'index'])->middleware('can:inventory.view')->name('movements.index');
        Route::get('movements/create', [StockMovementController::class, 'create'])->name('movements.create');
        Route::post('movements', [StockMovementController::class, 'store'])->name('movements.store');
        Route::get('movements/{movement}', [StockMovementController::class, 'show'])->middleware('can:inventory.view')->name('movements.show');

        // Penerimaan barang: draft bisa diubah/dihapus; setelah dikonfirmasi diterima,
        // stok masuk lewat mutasi Barang Masuk dan dokumen terkunci.
        Route::get('receipts', [GoodsReceiptController::class, 'index'])->middleware('can:receipts.view')->name('receipts.index');
        Route::get('receipts/create', [GoodsReceiptController::class, 'create'])->middleware('can:receipts.create')->name('receipts.create');
        Route::post('receipts', [GoodsReceiptController::class, 'store'])->middleware('can:receipts.create')->name('receipts.store');
        Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->middleware('can:receipts.view')->name('receipts.show');
        Route::get('receipts/{receipt}/edit', [GoodsReceiptController::class, 'edit'])->middleware('can:receipts.create')->name('receipts.edit');
        Route::put('receipts/{receipt}', [GoodsReceiptController::class, 'update'])->middleware('can:receipts.create')->name('receipts.update');
        Route::post('receipts/{receipt}/receive', [GoodsReceiptController::class, 'receive'])->middleware('can:receipts.receive')->name('receipts.receive');
        Route::delete('receipts/{receipt}', [GoodsReceiptController::class, 'destroy'])->middleware('can:receipts.delete')->name('receipts.destroy');

        // Stock opname: lembar hitung draft diisi hasil hitung, lalu diposting
        // (selisih masuk lewat mutasi Penyesuaian) dan terkunci.
        Route::get('counts', [StockCountController::class, 'index'])->middleware('can:opname.view')->name('counts.index');
        Route::get('counts/create', [StockCountController::class, 'create'])->middleware('can:opname.create')->name('counts.create');
        Route::post('counts', [StockCountController::class, 'store'])->middleware('can:opname.create')->name('counts.store');
        Route::get('counts/{count}', [StockCountController::class, 'show'])->middleware('can:opname.view')->name('counts.show');
        Route::get('counts/{count}/sheet', [StockCountController::class, 'sheet'])->middleware('can:opname.view')->name('counts.sheet');
        Route::put('counts/{count}', [StockCountController::class, 'update'])->middleware('can:opname.create')->name('counts.update');
        Route::delete('counts/{count}', [StockCountController::class, 'destroy'])->middleware('can:opname.delete')->name('counts.destroy');
    });

    // Cetak label barcode: label SKU produk & paket (hak lihat produk) dan label
    // lokasi bin (hak lihat gudang). Halaman cetak dikirim lewat POST supaya
    // daftar label yang panjang tidak terpotong batas panjang URL.
    Route::prefix('labels')->name('labels.')->group(function () {
        Route::middleware('can:products.view')->group(function () {
            Route::get('products', [LabelController::class, 'products'])->name('products');
            Route::get('products/lookup', [LabelController::class, 'productLookup'])->name('products.lookup');
            Route::match(['get', 'post'], 'products/print', [LabelController::class, 'printProducts'])->name('products.print');
        });
        Route::middleware('can:warehouses.view')->group(function () {
            Route::get('locations', [LabelController::class, 'locations'])->name('locations');
            Route::get('locations/lookup', [LabelController::class, 'locationLookup'])->name('locations.lookup');
            Route::match(['get', 'post'], 'locations/print', [LabelController::class, 'printLocations'])->name('locations.print');
        });
    });

    // Laporan stok; tiap laporan bisa diekspor ke Excel lewat ?export=xlsx.
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('stock-locations', [ReportController::class, 'stockLocations'])->name('stock-locations');
        Route::get('min-stock', [ReportController::class, 'minStock'])->name('min-stock');
        Route::get('reorder', [ReportController::class, 'reorder'])->name('reorder');
        Route::get('movements', [ReportController::class, 'movements'])->name('movements');
    });

    // Pesanan penjualan manual: draft -> dikonfirmasi (stok dicadangkan) -> diambil ->
    // dikemas -> dikirim (stok keluar lewat mutasi Barang Keluar). Batal melepas cadangan.
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('orders', [SalesOrderController::class, 'index'])->middleware('can:sales.view')->name('orders.index');
        Route::get('orders/create', [SalesOrderController::class, 'create'])->middleware('can:sales.create')->name('orders.create');
        Route::post('orders', [SalesOrderController::class, 'store'])->middleware('can:sales.create')->name('orders.store');
        Route::get('orders/import', [SalesOrderImportController::class, 'create'])->middleware('can:sales.create')->name('orders.import');
        Route::post('orders/import', [SalesOrderImportController::class, 'store'])->middleware('can:sales.create')->name('orders.import.store');
        Route::get('orders/import/template.{format}', [SalesOrderImportController::class, 'template'])->middleware('can:sales.create')->name('orders.import.template');
        Route::get('orders/{order}', [SalesOrderController::class, 'show'])->middleware('can:sales.view')->name('orders.show');
        Route::get('orders/{order}/edit', [SalesOrderController::class, 'edit'])->middleware('can:sales.create')->name('orders.edit');
        Route::put('orders/{order}', [SalesOrderController::class, 'update'])->middleware('can:sales.create')->name('orders.update');
        Route::delete('orders/{order}', [SalesOrderController::class, 'destroy'])->middleware('can:sales.delete')->name('orders.destroy');
        Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->middleware('can:sales.confirm')->name('orders.confirm');
        Route::post('orders/{order}/reopen', [SalesOrderController::class, 'reopen'])->middleware('can:sales.confirm')->name('orders.reopen');
        Route::post('orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->middleware('can:sales.cancel')->name('orders.cancel');
        Route::get('orders/{order}/picking', [SalesOrderController::class, 'picking'])->middleware('can:sales.fulfill')->name('orders.picking');
        Route::post('orders/{order}/pick', [SalesOrderController::class, 'pick'])->middleware('can:sales.fulfill')->name('orders.pick');
        Route::get('orders/{order}/pick-list', [SalesOrderController::class, 'pickList'])->middleware('can:sales.view')->name('orders.pick-list');
        Route::post('orders/{order}/pack', [SalesOrderController::class, 'pack'])->middleware('can:sales.fulfill')->name('orders.pack');
        Route::post('orders/{order}/ship', [SalesOrderController::class, 'ship'])->middleware('can:sales.fulfill')->name('orders.ship');

        // Retur penjualan: cari pesanan lewat resi / no. SO / no. pesanan, atau tanpa
        // pesanan. Draft bisa diubah/dihapus; saat diterima, barang layak jual masuk
        // stok lewat mutasi Barang Masuk dan dokumen terkunci. Barang rusak dicatat saja.
        Route::get('returns', [SalesReturnController::class, 'index'])->middleware('can:returns.view')->name('returns.index');
        Route::get('returns/create', [SalesReturnController::class, 'create'])->middleware('can:returns.create')->name('returns.create');
        Route::get('returns/lookup', [SalesReturnController::class, 'lookup'])->middleware('can:returns.create')->name('returns.lookup');
        Route::post('returns', [SalesReturnController::class, 'store'])->middleware('can:returns.create')->name('returns.store');
        Route::get('returns/{return}', [SalesReturnController::class, 'show'])->middleware('can:returns.view')->name('returns.show');
        Route::get('returns/{return}/edit', [SalesReturnController::class, 'edit'])->middleware('can:returns.create')->name('returns.edit');
        Route::put('returns/{return}', [SalesReturnController::class, 'update'])->middleware('can:returns.create')->name('returns.update');
        Route::post('returns/{return}/receive', [SalesReturnController::class, 'receive'])->middleware('can:returns.receive')->name('returns.receive');
        Route::delete('returns/{return}', [SalesReturnController::class, 'destroy'])->middleware('can:returns.delete')->name('returns.destroy');
    });

    // Integrasi Shopee Open Platform (app Seller In House System). Shopee
    // mengarahkan pemilik toko kembali ke shopee/callback setelah otorisasi.
    Route::prefix('shopee')->name('shopee.')->group(function () {
        Route::get('settings', [ShopeeSettingController::class, 'edit'])->middleware('can:shopee.settings')->name('settings.edit');
        Route::put('settings', [ShopeeSettingController::class, 'update'])->middleware('can:shopee.settings')->name('settings.update');

        Route::get('shops', [ShopeeShopController::class, 'index'])->middleware('can:shopee.view')->name('shops.index');
        Route::post('shops/connect', [ShopeeShopController::class, 'connect'])->middleware('can:shopee.connect')->name('shops.connect');
        Route::get('callback', [ShopeeShopController::class, 'callback'])->middleware('can:shopee.connect')->name('callback');
        Route::put('shops/{shop}', [ShopeeShopController::class, 'update'])->middleware('can:shopee.connect')->name('shops.update');
        Route::post('shops/{shop}/refresh', [ShopeeShopController::class, 'refresh'])->middleware('can:shopee.connect')->name('shops.refresh');
        Route::post('shops/{shop}/sync', [ShopeeShopController::class, 'sync'])->middleware('can:shopee.sync')->name('shops.sync');
        Route::delete('shops/{shop}', [ShopeeShopController::class, 'destroy'])->middleware('can:shopee.connect')->name('shops.destroy');

        Route::get('mapping-targets', [ShopeeSkuMappingController::class, 'lookup'])->middleware('can:shopee.mapping')->name('mappings.lookup');
        Route::get('shops/{shop}/mappings', [ShopeeSkuMappingController::class, 'index'])->middleware('can:shopee.view')->name('mappings.index');
        Route::post('shops/{shop}/mappings', [ShopeeSkuMappingController::class, 'store'])->middleware('can:shopee.mapping')->name('mappings.store');
        Route::post('shops/{shop}/mappings/auto-match', [ShopeeSkuMappingController::class, 'autoMatch'])->middleware('can:shopee.mapping')->name('mappings.auto-match');
        Route::post('shops/{shop}/mappings/pull', [ShopeeSkuMappingController::class, 'pull'])->middleware('can:shopee.mapping')->name('mappings.pull');
        Route::put('shops/{shop}/mappings/{mapping}', [ShopeeSkuMappingController::class, 'update'])->middleware('can:shopee.mapping')->name('mappings.update');
        Route::delete('shops/{shop}/mappings/{mapping}', [ShopeeSkuMappingController::class, 'destroy'])->middleware('can:shopee.mapping')->name('mappings.destroy');
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

        Route::get('activity', [ActivityLogController::class, 'index'])->middleware('can:activity.view')->name('activity.index');

        // Backup database: otomatis tiap hari lewat scheduler, atau manual dari sini.
        Route::get('backups', [BackupController::class, 'index'])->middleware('can:backups.view')->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->middleware('can:backups.create')->name('backups.store');
        Route::get('backups/{name}', [BackupController::class, 'download'])->middleware('can:backups.view')->name('backups.download');
        Route::delete('backups/{name}', [BackupController::class, 'destroy'])->middleware('can:backups.delete')->name('backups.destroy');
    });
});
