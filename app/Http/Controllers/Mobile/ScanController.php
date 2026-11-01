<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\WarehouseLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tombol Scan di tengah menu bawah: satu scan, aplikasi menebak maksudnya.
 * Resi / no. pesanan -> ringkasan pesanan & aksi berikutnya, kode rak -> isi rak,
 * SKU / barcode -> stok barang di gudang aktif.
 */
class ScanController extends Controller
{
    use MobileContext;

    public function __invoke(Request $request): View|RedirectResponse
    {
        $code = trim((string) $request->query('code'));
        if ($code === '') {
            return redirect()->route('mobile.home');
        }

        $user = $request->user();
        $warehouseId = $this->mobileWarehouseId($request);

        if ($user->canAny(['sales.view', 'sales.fulfill', 'returns.create']) && ($order = SalesOrder::findByScan($code))) {
            return view('mobile.scan-order', ['order' => $order->load(['courier', 'warehouse', 'items.variant.product'])]);
        }

        $location = WarehouseLocation::query()->where('warehouse_id', $warehouseId)->where('code', $code)->first();
        if ($location) {
            return view('mobile.scan-location', [
                'location' => $location,
                'stocks' => Stock::query()->with('variant.product')->where('warehouse_location_id', $location->id)->where('qty', '!=', 0)->get(),
            ]);
        }

        $variant = ProductVariant::query()->with('product')->where('sku', $code)->orWhere('barcode', $code)->first();
        if ($variant) {
            return view('mobile.scan-variant', [
                'variant' => $variant,
                'stocks' => Stock::query()->with('location')->where('warehouse_id', $warehouseId)->where('product_variant_id', $variant->id)->where('qty', '!=', 0)->get(),
            ]);
        }

        $bundle = Bundle::query()->with('items.variant.product')->where('sku', $code)->orWhere('barcode', $code)->first();

        return view('mobile.scan-none', ['code' => $code, 'bundle' => $bundle]);
    }
}
