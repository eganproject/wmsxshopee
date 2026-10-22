<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\DamagedDocumentController;
use App\Http\Controllers\Inventory\DamagedStockController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\OpeningStockImportController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockCountController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\VariantLookupController;
use App\Http\Controllers\Inventory\WarehouseTransferController;
use App\Http\Controllers\Inventory\WarehouseVariantSettingController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\Marketplace\MarketplaceController;
use App\Http\Controllers\Marketplace\MarketplaceProductStatusController;
use App\Http\Controllers\Marketplace\MarketplaceSyncLogController;
use App\Http\Controllers\Master\BundleController;
use App\Http\Controllers\Master\CourierController;
use App\Http\Controllers\Master\LocationController;
use App\Http\Controllers\Master\LocationImportController;
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
use App\Http\Controllers\Sales\HandoverController;
use App\Http\Controllers\Sales\OrderSkuMappingController;
use App\Http\Controllers\Sales\QcController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesOrderImportController;
use App\Http\Controllers\Sales\SalesReturnController;
use App\Http\Controllers\Sales\ShipmentController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Shopee\ShopeeSettingController;
use App\Http\Controllers\Shopee\ShopeeShopController;
use App\Http\Controllers\Shopee\ShopeeSkuMappingController;
use App\Http\Controllers\Shopee\ShopeeWebhookController;
use App\Http\Controllers\Tiktok\TiktokSettingController;
use App\Http\Controllers\Tiktok\TiktokShopController;
use App\Http\Controllers\Tiktok\TiktokSkuMappingController;
use App\Http\Controllers\Tiktok\TiktokWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Push Mechanism Shopee (tanpa login; diverifikasi lewat tanda tangan partner key).
Route::post('shopee/webhook', ShopeeWebhookController::class)->name('shopee.webhook');
// Webhook TikTok Shop (tanpa login; diverifikasi lewat tanda tangan app secret).
Route::post('tiktok/webhook', TiktokWebhookController::class)->name('tiktok.webhook');

// Tidak ada route register: akun hanya dibuat lewat admin panel.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('notifications', NotificationController::class)->name('notifications.index');
    Route::get('settings', SettingsController::class)->name('settings');

    // Pencarian global (Ctrl+K). Tiap grup dicek hak lihatnya di App\Support\GlobalSearch.
    Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('search');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::prefix('master')->name('master.')->group(function () {
        // Impor massal produk dari Excel/CSV.
        Route::get('products/import', [ProductImportController::class, 'create'])->middleware('can:products.create')->name('products.import');
        Route::post('products/import', [ProductImportController::class, 'store'])->middleware('can:products.create')->name('products.import.store');
        Route::get('products/import/template.{format}', [ProductImportController::class, 'template'])->middleware('can:products.create')->name('products.import.template');

        // Impor massal zona, rak & lokasi bin dari Excel/CSV.
        Route::get('locations/import', [LocationImportController::class, 'create'])->middleware('can:warehouses.create')->name('locations.import');
        Route::post('locations/import', [LocationImportController::class, 'store'])->middleware('can:warehouses.create')->name('locations.import.store');
        Route::get('locations/import/template.{format}', [LocationImportController::class, 'template'])->middleware('can:warehouses.create')->name('locations.import.template');

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

        // Transfer antar gudang (gudang besar -> gudang kecil): kirim, terima, batal.
        Route::get('transfers', [WarehouseTransferController::class, 'index'])->middleware('can:inventory.view')->name('transfers.index');
        Route::get('transfers/create', [WarehouseTransferController::class, 'create'])->middleware('can:inventory.create')->name('transfers.create');
        Route::post('transfers', [WarehouseTransferController::class, 'store'])->middleware('can:inventory.create')->name('transfers.store');
        Route::get('transfers/{transfer}', [WarehouseTransferController::class, 'show'])->middleware('can:inventory.view')->name('transfers.show');
        Route::post('transfers/{transfer}/receive', [WarehouseTransferController::class, 'receive'])->middleware('can:inventory.create')->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [WarehouseTransferController::class, 'cancel'])->middleware('can:inventory.create')->name('transfers.cancel');

        // Lokasi tetap SKU & stok min/maks per gudang.
        Route::get('sku-settings', [WarehouseVariantSettingController::class, 'index'])->middleware('can:inventory.view')->name('sku-settings.index');
        Route::put('sku-settings', [WarehouseVariantSettingController::class, 'update'])->middleware('can:inventory.create')->name('sku-settings.update');

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

        // Stok rusak per gudang, terpisah dari stok jual. Masuk dari penerimaan, retur
        // penjualan atau Pindah ke Rusak; keluar lewat retur ke supplier, pemusnahan,
        // perbaikan (kembali ke stok baik) atau rakit / campur. Hak buat dicek per jenis
        // di controller. Draft bisa diubah/dihapus; stok berubah saat disetujui.
        Route::middleware('can:damaged.view')->group(function () {
            Route::get('damaged', [DamagedStockController::class, 'index'])->name('damaged.index');
            Route::get('damaged/ledger', [DamagedStockController::class, 'ledger'])->name('damaged.ledger');
            Route::get('damaged/documents', [DamagedDocumentController::class, 'index'])->name('damaged.documents.index');
            Route::get('damaged/documents/create', [DamagedDocumentController::class, 'create'])->name('damaged.documents.create');
            Route::post('damaged/documents', [DamagedDocumentController::class, 'store'])->name('damaged.documents.store');
            Route::get('damaged/documents/{document}', [DamagedDocumentController::class, 'show'])->name('damaged.documents.show');
            Route::get('damaged/documents/{document}/edit', [DamagedDocumentController::class, 'edit'])->name('damaged.documents.edit');
            Route::put('damaged/documents/{document}', [DamagedDocumentController::class, 'update'])->name('damaged.documents.update');
            Route::delete('damaged/documents/{document}', [DamagedDocumentController::class, 'destroy'])->name('damaged.documents.destroy');
            Route::post('damaged/documents/{document}/approve', [DamagedDocumentController::class, 'approve'])->middleware('can:damaged.approve')->name('damaged.documents.approve');
        });

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
        Route::get('qc', [QcController::class, 'index'])->middleware('can:sales.fulfill')->name('qc.index');
        Route::post('qc', [QcController::class, 'lookup'])->middleware('can:sales.fulfill')->name('qc.lookup');
        Route::get('qc/{order}', [QcController::class, 'show'])->middleware('can:sales.fulfill')->name('qc.show');
        Route::post('qc/{order}', [QcController::class, 'store'])->middleware('can:sales.fulfill')->name('qc.store');
        Route::get('handovers', [HandoverController::class, 'index'])->middleware('can:sales.fulfill')->name('handovers.index');
        Route::post('handovers/scan', [HandoverController::class, 'scan'])->middleware('can:sales.fulfill')->name('handovers.scan');
        Route::get('handovers/{handover}', [HandoverController::class, 'show'])->middleware('can:sales.fulfill')->name('handovers.show');
        Route::get('handovers/{handover}/manifest', [HandoverController::class, 'manifest'])->middleware('can:sales.fulfill')->name('handovers.manifest');
        Route::post('orders/pick', [SalesOrderController::class, 'bulkPick'])->middleware('can:sales.fulfill')->name('orders.pick.bulk');
        Route::post('orders/shipment', [ShipmentController::class, 'bulk'])->middleware('can:sales.fulfill')->name('orders.shipment.bulk');
        Route::get('orders/shipping-labels', [ShipmentController::class, 'bulkLabel'])->middleware('can:sales.fulfill')->name('orders.shipping-labels');
        Route::get('orders/shipping-labels/part', [ShipmentController::class, 'labelPart'])->middleware('can:sales.fulfill')->name('orders.shipping-labels.part');
        Route::get('orders/{order}', [SalesOrderController::class, 'show'])->middleware('can:sales.view')->name('orders.show');
        Route::get('orders/{order}/edit', [SalesOrderController::class, 'edit'])->middleware('can:sales.create')->name('orders.edit');
        Route::put('orders/{order}', [SalesOrderController::class, 'update'])->middleware('can:sales.create')->name('orders.update');
        Route::delete('orders/{order}', [SalesOrderController::class, 'destroy'])->middleware('can:sales.delete')->name('orders.destroy');
        Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->middleware('can:sales.confirm')->name('orders.confirm');
        Route::post('orders/{order}/reopen', [SalesOrderController::class, 'reopen'])->middleware('can:sales.confirm')->name('orders.reopen');
        Route::post('orders/{order}/resync', [SalesOrderController::class, 'resync'])->middleware('can:sales.confirm')->name('orders.resync');
        Route::post('orders/{order}/map-sku', [OrderSkuMappingController::class, 'store'])->middleware('can:sales.view')->name('orders.map-sku');
        Route::post('orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->middleware('can:sales.cancel')->name('orders.cancel');
        Route::get('orders/{order}/picking', [SalesOrderController::class, 'picking'])->middleware('can:sales.fulfill')->name('orders.picking');
        Route::post('orders/{order}/pick', [SalesOrderController::class, 'pick'])->middleware('can:sales.fulfill')->name('orders.pick');
        Route::get('orders/{order}/pick-list', [SalesOrderController::class, 'pickList'])->middleware('can:sales.view')->name('orders.pick-list');
        Route::post('orders/{order}/pack', [SalesOrderController::class, 'pack'])->middleware('can:sales.skip_scan')->name('orders.pack');
        Route::post('orders/{order}/ship', [SalesOrderController::class, 'ship'])->middleware('can:sales.skip_scan')->name('orders.ship');
        Route::post('orders/{order}/hand-to-customer', [SalesOrderController::class, 'handToCustomer'])->middleware('can:sales.fulfill')->name('orders.hand-to-customer');
        Route::get('orders/{order}/shipment', [ShipmentController::class, 'create'])->middleware('can:sales.fulfill')->name('orders.shipment');
        Route::post('orders/{order}/shipment', [ShipmentController::class, 'store'])->middleware('can:sales.fulfill')->name('orders.shipment.store');
        Route::get('orders/{order}/shipping-label', [ShipmentController::class, 'label'])->middleware('can:sales.fulfill')->name('orders.shipping-label');

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

    // Marketplace: satu menu untuk toko, status produk & kredensial Shopee dan TikTok.
    Route::prefix('marketplace')->name('marketplace.')->group(function () {
        Route::get('shops', [MarketplaceController::class, 'shops'])->middleware('can:marketplace.view')->name('shops');
        Route::get('products', MarketplaceProductStatusController::class)->middleware('can:marketplace.view')->name('products');
        Route::post('sync-products', [MarketplaceController::class, 'syncProducts'])->middleware('can:marketplace.view')->name('sync-products');
        Route::post('sync-all', [MarketplaceController::class, 'syncAll'])->middleware('can:marketplace.view')->name('sync-all');
        Route::post('shops/{platform}/{shop}/sync-all', [MarketplaceController::class, 'syncShop'])->whereIn('platform', ['shopee', 'tiktok'])->whereNumber('shop')->middleware('can:marketplace.view')->name('shops.sync-all');
        Route::get('sync-logs', [MarketplaceSyncLogController::class, 'index'])->middleware('can:marketplace.view')->name('sync-logs');
        Route::post('sync-logs/retry-stock', [MarketplaceSyncLogController::class, 'retryStock'])->middleware('can:marketplace.view')->name('sync-logs.retry-stock');
        Route::post('sync-logs/retry-push', [MarketplaceSyncLogController::class, 'retryPush'])->middleware('can:marketplace.view')->name('sync-logs.retry-push');
        Route::get('settings', [MarketplaceController::class, 'settings'])->middleware('can:marketplace.settings')->name('settings');
    });

    // Integrasi Shopee Open Platform (app Seller In House System). Shopee
    // mengarahkan pemilik toko kembali ke shopee/callback setelah otorisasi.
    Route::prefix('shopee')->name('shopee.')->group(function () {
        // Halaman lama digabung ke menu Marketplace; URL lama tetap diarahkan.
        Route::get('settings', fn () => redirect()->route('marketplace.settings', ['tab' => 'shopee']))->name('settings.edit');
        Route::put('settings', [ShopeeSettingController::class, 'update'])->middleware('can:shopee.settings')->name('settings.update');

        Route::get('shops', fn () => redirect()->route('marketplace.shops', ['platform' => 'shopee']))->name('shops.index');
        Route::post('shops/connect', [ShopeeShopController::class, 'connect'])->middleware('can:shopee.connect')->name('shops.connect');
        Route::get('callback', [ShopeeShopController::class, 'callback'])->middleware('can:shopee.connect')->name('callback');
        Route::put('shops/{shop}', [ShopeeShopController::class, 'update'])->middleware('can:shopee.connect')->name('shops.update');
        Route::post('shops/{shop}/refresh', [ShopeeShopController::class, 'refresh'])->middleware('can:shopee.connect')->name('shops.refresh');
        Route::post('shops/{shop}/sync', [ShopeeShopController::class, 'sync'])->middleware('can:shopee.sync')->name('shops.sync');
        Route::delete('shops/{shop}', [ShopeeShopController::class, 'destroy'])->middleware('can:shopee.connect')->name('shops.destroy');

        Route::get('products', fn () => redirect()->route('marketplace.products', ['platform' => 'shopee']))->name('products.index');
        Route::get('mapping-targets', [ShopeeSkuMappingController::class, 'lookup'])->middleware('can:shopee.mapping')->name('mappings.lookup');
        Route::get('shops/{shop}/mappings', [ShopeeSkuMappingController::class, 'index'])->middleware('can:shopee.view')->name('mappings.index');
        Route::post('shops/{shop}/mappings', [ShopeeSkuMappingController::class, 'store'])->middleware('can:shopee.mapping')->name('mappings.store');
        Route::post('shops/{shop}/mappings/auto-match', [ShopeeSkuMappingController::class, 'autoMatch'])->middleware('can:shopee.mapping')->name('mappings.auto-match');
        Route::post('shops/{shop}/mappings/pull', [ShopeeSkuMappingController::class, 'pull'])->middleware('can:shopee.mapping')->name('mappings.pull');
        Route::put('shops/{shop}/mappings/{mapping}', [ShopeeSkuMappingController::class, 'update'])->middleware('can:shopee.mapping')->name('mappings.update');
        Route::delete('shops/{shop}/mappings/{mapping}', [ShopeeSkuMappingController::class, 'destroy'])->middleware('can:shopee.mapping')->name('mappings.destroy');
    });

    // Integrasi TikTok Shop Partner Center. TikTok mengarahkan pemilik toko
    // kembali ke tiktok/callback setelah otorisasi.
    Route::prefix('tiktok')->name('tiktok.')->group(function () {
        Route::get('settings', fn () => redirect()->route('marketplace.settings', ['tab' => 'tiktok']))->name('settings.edit');
        Route::put('settings', [TiktokSettingController::class, 'update'])->middleware('can:tiktok.settings')->name('settings.update');

        Route::get('shops', fn () => redirect()->route('marketplace.shops', ['platform' => 'tiktok']))->name('shops.index');
        Route::post('shops/connect', [TiktokShopController::class, 'connect'])->middleware('can:tiktok.connect')->name('shops.connect');
        Route::get('callback', [TiktokShopController::class, 'callback'])->middleware('can:tiktok.connect')->name('callback');
        Route::put('shops/{shop}', [TiktokShopController::class, 'update'])->middleware('can:tiktok.connect')->name('shops.update');
        Route::post('shops/{shop}/refresh', [TiktokShopController::class, 'refresh'])->middleware('can:tiktok.connect')->name('shops.refresh');
        Route::post('shops/{shop}/sync', [TiktokShopController::class, 'sync'])->middleware('can:tiktok.sync')->name('shops.sync');
        Route::delete('shops/{shop}', [TiktokShopController::class, 'destroy'])->middleware('can:tiktok.connect')->name('shops.destroy');

        Route::get('mapping-targets', [ShopeeSkuMappingController::class, 'lookup'])->middleware('can:tiktok.mapping')->name('mappings.lookup');
        Route::get('shops/{shop}/mappings', [TiktokSkuMappingController::class, 'index'])->middleware('can:tiktok.view')->name('mappings.index');
        Route::post('shops/{shop}/mappings', [TiktokSkuMappingController::class, 'store'])->middleware('can:tiktok.mapping')->name('mappings.store');
        Route::post('shops/{shop}/mappings/auto-match', [TiktokSkuMappingController::class, 'autoMatch'])->middleware('can:tiktok.mapping')->name('mappings.auto-match');
        Route::post('shops/{shop}/mappings/pull', [TiktokSkuMappingController::class, 'pull'])->middleware('can:tiktok.mapping')->name('mappings.pull');
        Route::put('shops/{shop}/mappings/{mapping}', [TiktokSkuMappingController::class, 'update'])->middleware('can:tiktok.mapping')->name('mappings.update');
        Route::delete('shops/{shop}/mappings/{mapping}', [TiktokSkuMappingController::class, 'destroy'])->middleware('can:tiktok.mapping')->name('mappings.destroy');
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
