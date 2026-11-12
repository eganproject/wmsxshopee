<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\WarehouseLocation;
use App\Services\FixedBins;
use App\Support\Barcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data master gudang aktif untuk aplikasi: disimpan di HP sehingga scan SKU /
 * bin tetap dikenali saat offline. Diperbarui tiap aplikasi online.
 */
class CatalogController extends Controller
{
    use MobileContext;

    public function __invoke(Request $request, FixedBins $bins): JsonResponse
    {
        $warehouseId = $this->mobileWarehouseId($request);

        $fixed = $bins->for([$warehouseId])->mapWithKeys(function ($rows, $key) {
            return [(int) explode(':', $key)[1] => $rows->pluck('location_id')->all()];
        });

        $variants = ProductVariant::query()
            ->with('product:id,name,unit_id', 'product.unit:id,code')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->orderBy('sku')
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode, 'aliases' => Barcodes::aliasesOf($variant->id),
                'name' => $variant->product->name,
                'variant' => $variant->name,
                'unit' => $variant->product->unit?->code,
                'bins' => $fixed[$variant->id] ?? [],
            ])
            ->values();

        return response()->json([
            'warehouse_id' => $warehouseId,
            'generated_at' => now()->toIso8601String(),
            'variants' => $variants,
            'bundles' => Bundle::query()->with('items')->where('is_active', true)->get()->map(fn (Bundle $bundle) => [
                'id' => $bundle->id,
                'sku' => $bundle->sku,
                'barcode' => $bundle->barcode,
                'name' => $bundle->name,
                'items' => $bundle->items->map(fn ($item) => ['variant_id' => $item->product_variant_id, 'qty' => (int) $item->qty])->values(),
            ])->values(),
            'locations' => WarehouseLocation::query()
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code'])
                ->map(fn ($location) => ['id' => $location->id, 'code' => $location->code])
                ->values(),
            'suppliers' => $request->user()->can('receipts.create')
                ? Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
                : [],
            'stock' => Stock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('qty', '!=', 0)
                ->get(['product_variant_id', 'warehouse_location_id', 'qty'])
                ->map(fn ($stock) => [$stock->product_variant_id, $stock->warehouse_location_id, (int) $stock->qty])
                ->values(),
        ]);
    }
}
