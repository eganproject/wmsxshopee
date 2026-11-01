<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\WarehouseLocation;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cek stok cepat satu rak: scan bin, hitung ulang, dan selisihnya diajukan
 * sebagai dokumen Penyesuaian Stok yang menunggu approval supervisor.
 * Stok baru berubah setelah disetujui di Optima web.
 */
class StockCheckController extends StockAdjustmentController
{
    use MobileContext;

    public function mobileIndex(Request $request): View
    {
        return view('mobile.stock-check', [
            'warehouse' => $this->mobileWarehouse($request),
            'reasons' => StockAdjustment::REASONS,
        ]);
    }

    /** Isi rak saat ini (stok sistem per barang). */
    public function bin(Request $request): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'kode rak'])['code']);
        $location = WarehouseLocation::query()
            ->where('warehouse_id', $this->mobileWarehouseId($request))
            ->where('code', $code)
            ->first();

        if (! $location) {
            return response()->json(['message' => "Rak \"{$code}\" tidak ada di gudang ini."], 404);
        }

        $stocks = Stock::query()
            ->with('variant.product')
            ->where('warehouse_location_id', $location->id)
            ->where('qty', '!=', 0)
            ->get();

        return response()->json([
            'location' => ['id' => $location->id, 'code' => $location->code, 'active' => (bool) $location->is_active],
            'items' => $stocks->map(fn ($stock) => [
                'variant_id' => $stock->product_variant_id,
                'sku' => $stock->variant->sku,
                'barcode' => $stock->variant->barcode,
                'name' => $stock->variant->fullName(),
                'system_qty' => (int) $stock->qty,
            ])->values(),
        ]);
    }

    public function mobileStore(Request $request, StockAdjustmentService $service): JsonResponse
    {
        $request->merge([
            'warehouse_id' => $this->mobileWarehouseId($request),
            'adjustment_date' => now()->toDateString(),
        ]);
        [$header, $items] = $this->validated($request);

        $adjustment = $service->draft([...$header, 'created_by' => $request->user()->id], $items, submit: true);

        return response()->json([
            'message' => "Penyesuaian {$adjustment->number} diajukan dan menunggu approval.",
            'number' => $adjustment->number,
        ]);
    }
}
