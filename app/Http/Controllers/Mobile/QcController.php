<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\ScanDuplicateAttempt;
use App\Services\FulfillmentScan;
use App\Services\QcControl;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * QC kemas di aplikasi: langkah 1 scan resi / no. pesanan, langkah 2 scan tiap
 * barang (SKU / barcode / kode paket), lalu foto paket (opsional) dan selesai.
 */
class QcController extends Controller
{
    use MobileContext;

    public function index(Request $request): View
    {
        return view('mobile.qc', ['warehouse' => $this->mobileWarehouse($request)]);
    }

    /** Antrean QC gudang aktif, disimpan di HP supaya QC tetap bisa jalan saat sinyal hilang. */
    public function queue(Request $request, FulfillmentScan $scan): JsonResponse
    {
        $orders = SalesOrder::query()
            ->with(['courier', 'qcHolder'])
            ->where('warehouse_id', $this->mobileWarehouseId($request))
            ->where('status', 'picking')
            ->oldest('picking_at')
            ->oldest('id')
            ->limit(300)
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'orders' => $orders->map(fn (SalesOrder $order) => $this->payload($order, $scan))->values(),
        ]);
    }

    /** Langkah 1: cari pesanan dari scan resi / no. SO / no. pesanan marketplace. */
    public function find(Request $request, FulfillmentScan $scan): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'resi'])['code']);
        $order = SalesOrder::findByScan($code);

        if (! $order) {
            return response()->json(['message' => "Resi / pesanan \"{$code}\" tidak ditemukan."], 404);
        }

        if ($order->hasStatus('packed', 'shipped')) {
            ScanDuplicateAttempt::record($order, $code, 'qc', $request->user()->id);

            return response()->json(['message' => "Scan ganda: {$order->number} sudah lolos QC ({$order->statusLabel()}). Jangan kemas paket kedua."], 422);
        }

        if (! $order->hasStatus('picking')) {
            return response()->json(['message' => "{$order->number} berstatus {$order->statusLabel()}. QC hanya untuk pesanan yang Sedang Diambil."], 422);
        }

        $order->load(['courier', 'qcHolder']);

        return response()->json(['order' => $this->payload($order, $scan)]);
    }

    /** Langkah 2 selesai: jumlah scan harus cocok persis, lalu pesanan jadi Sudah Dikemas. */
    public function store(Request $request, SalesOrder $order, FulfillmentScan $scan): JsonResponse
    {
        $data = $request->validate([
            'scanned' => ['present', 'array'],
            'scanned.*' => ['integer', 'min:0'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], ['photo.max' => 'Foto paket maksimal 5 MB.'], ['photo' => 'foto paket']);

        if ($order->hasStatus('packed') || $order->hasStatus('shipped')) {
            // Dikirim ulang dari antrean offline padahal sudah tersimpan sebelumnya.
            return response()->json(['message' => "{$order->number} sudah lolos QC.", 'order' => $order->number, 'duplicate' => true]);
        }

        Activity::track($order, fn () => $scan->completeQc($order, $data['scanned'], $request->user()->id));

        if ($request->hasFile('photo')) {
            $order->update(['packing_photo_path' => $request->file('photo')->store("packing/{$order->id}", 'public')]);
        }

        $order->refresh();

        return response()->json([
            'message' => "QC {$order->number} cocok. Paket siap diserahkan ke kurir.",
            'order' => $order->number,
            'label_url' => $order->hasShippingLabel() ? route('sales.orders.shipping-label', $order) : null,
        ]);
    }

    /** Tahan QC dengan alasan (barang rusak, kurang, ragu). */
    public function hold(Request $request, SalesOrder $order, QcControl $qc, FulfillmentScan $scan): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $qc->hold($order, $data['reason'], $request->user()->id);

        return response()->json(['message' => "QC {$order->number} ditahan.", 'order' => $this->payload($order->refresh()->load(['courier', 'qcHolder']), $scan)]);
    }

    public function release(SalesOrder $order, QcControl $qc, FulfillmentScan $scan): JsonResponse
    {
        $qc->release($order);

        return response()->json(['message' => "Tahanan QC {$order->number} dilepas.", 'order' => $this->payload($order->refresh()->load(['courier', 'qcHolder']), $scan)]);
    }

    /** @return array<string, mixed> */
    private function payload(SalesOrder $order, FulfillmentScan $scan): array
    {
        $expected = $scan->expected($order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'tracking' => $order->tracking_number,
            'external' => $order->external_number,
            'customer' => $order->customer_name,
            'channel' => $order->isOutbound() ? 'Barang Keluar · '.$order->outboundPurposeLabel() : $order->channel_name,
            'courier' => $order->courier?->name,
            'lines' => $expected['lines'],
            'bundles' => $expected['bundles'],
            'store_url' => route('mobile.qc.store', $order),
            'hold_url' => route('mobile.qc.hold', $order),
            'release_url' => route('mobile.qc.release', $order),
            'warehouse_id' => $order->warehouse_id,
            'hold' => $order->isQcHeld() ? [
                'reason' => $order->qc_hold_reason,
                'by' => $order->qcHolder?->name,
                'at' => $order->qc_held_at->format('d/m H:i'),
            ] : null,
        ];
    }
}
