<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Models\GoodsReceipt;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Penerimaan barang dari HP: foto surat jalan, scan barang, jumlah diterima /
 * rusak, lalu simpan draft atau langsung terima. Lokasi bin boleh dikosongkan
 * (stok masuk tanpa bin); bila diisi, halaman putaway menuntun penyimpanan.
 * Validasi & posting stok memakai aturan yang sama dengan Optima web.
 */
class ReceiptController extends GoodsReceiptController
{
    use MobileContext;

    public function mobileIndex(Request $request): View
    {
        $receipts = GoodsReceipt::query()
            ->with('supplier')
            ->withSum('items', 'qty_received')
            ->where('warehouse_id', $this->mobileWarehouseId($request))
            ->latest('id')
            ->limit(30)
            ->get();

        return view('mobile.receipts', ['receipts' => $receipts, 'warehouse' => $this->mobileWarehouse($request)]);
    }

    public function mobileCreate(Request $request): View
    {
        return view('mobile.receipt-form', ['warehouse' => $this->mobileWarehouse($request)]);
    }

    public function mobileStore(Request $request, StockService $stock): JsonResponse
    {
        // Cek hak terima sebelum menyimpan apa pun, supaya tidak tertinggal draft setengah jadi.
        abort_if($request->input('action') === 'receive' && ! $request->user()->can('receipts.receive'), 403, 'Akun ini tidak punya hak menerima barang.');
        $request->merge(['receipt_date' => $request->input('receipt_date', now()->toDateString())]);
        $data = $this->validated($request);

        $receipt = DB::transaction(function () use ($request, $data) {
            $receipt = GoodsReceipt::create([
                ...$data['header'],
                'number' => GoodsReceipt::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $receipt->items()->createMany($data['items']);
            $this->storeAttachment($request, $receipt);

            return $receipt;
        });

        $received = false;
        if ($request->input('action') === 'receive') {
            try {
                $this->post($request, $receipt, $stock);
                $received = true;
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => "Draft {$receipt->number} tersimpan, tapi belum bisa diterima: ".collect($e->errors())->flatten()->first(),
                    'number' => $receipt->number,
                    'received' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $received ? $this->receivedMessage($receipt) : "Draft penerimaan {$receipt->number} tersimpan.",
            'number' => $receipt->number,
            'received' => $received,
            'putaway' => $this->putaway($receipt),
        ]);
    }

    public function mobileShow(GoodsReceipt $receipt): View
    {
        $receipt->load(['supplier', 'items.variant.product', 'items.location']);

        return view('mobile.receipt-show', ['receipt' => $receipt, 'putaway' => $this->putaway($receipt)]);
    }

    public function mobileReceive(Request $request, GoodsReceipt $receipt, StockService $stock): JsonResponse
    {
        $this->post($request, $receipt, $stock);

        return response()->json(['message' => $this->receivedMessage($receipt), 'number' => $receipt->number, 'received' => true]);
    }

    /**
     * Daftar simpan barang ke rak (hanya baris yang punya lokasi bin).
     *
     * @return list<array<string, mixed>>
     */
    private function putaway(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing(['items.variant.product', 'items.location']);

        return $receipt->items
            ->filter(fn ($item) => $item->location && $item->qty_received > 0)
            ->map(fn ($item) => [
                'sku' => $item->variant->sku,
                'name' => $item->variant->fullName(),
                'qty' => (int) $item->qty_received,
                'location' => $item->location->code,
            ])
            ->values()
            ->all();
    }
}
