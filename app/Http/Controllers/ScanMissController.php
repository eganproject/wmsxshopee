<?php

namespace App\Http\Controllers;

use App\Models\BarcodeScanMiss;
use App\Models\SalesOrder;
use App\Support\Barcodes;
use App\Support\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Laporan dari halaman scan (web & aplikasi) bahwa sebuah kode tidak dikenali.
 * Server mengecek ulang; kode yang ternyata dikenali tidak dicatat.
 */
class ScanMissController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'context' => ['required', 'string', Rule::in(array_keys(BarcodeScanMiss::CONTEXTS))],
            'warehouse_id' => ['nullable', 'integer'],
            'sales_order_id' => ['nullable', 'integer'],
        ]);

        $ids = WarehouseAccess::ids();
        $warehouseId = isset($data['warehouse_id']) && ($ids === null || in_array((int) $data['warehouse_id'], $ids, true)) ? (int) $data['warehouse_id'] : null;
        // Pesanan hanya disimpan sebagai tautan bila memang boleh dilihat user ini.
        $orderId = isset($data['sales_order_id']) && SalesOrder::whereKey($data['sales_order_id'])->exists() ? (int) $data['sales_order_id'] : null;
        $miss = Barcodes::recordMiss($data['code'], $data['context'], $request->user()->id, $warehouseId, $orderId);

        return response()->json(['logged' => $miss !== null]);
    }
}
