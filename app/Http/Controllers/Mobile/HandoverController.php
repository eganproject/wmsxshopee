<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\ShipmentHandover;
use App\Services\FulfillmentScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Serah terima ke kurir: scan beruntun (tiap scan langsung mengirim pesanan dan
 * masuk manifest harian per kurir), lalu kurir tanda tangan di HP.
 */
class HandoverController extends Controller
{
    use MobileContext;

    public function index(Request $request): View
    {
        $warehouseId = $this->mobileWarehouseId($request);
        ShipmentHandover::closeStale();

        $ready = SalesOrder::query()
            ->with('courier')
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'packed')
            ->where('delivery_type', 'courier')
            ->get(['id', 'courier_id'])
            ->groupBy(fn ($order) => $order->courier?->name ?? 'Tanpa kurir')
            ->map->count()
            ->sortDesc();

        $manifests = ShipmentHandover::query()
            ->with('courier')
            ->withCount('orders')
            ->where('warehouse_id', $warehouseId)
            ->where('created_at', '>=', today())
            ->latest('id')
            ->get();

        return view('mobile.handover', [
            'warehouse' => $this->mobileWarehouse($request),
            'ready' => $ready,
            'manifests' => $manifests,
        ]);
    }

    public function scan(Request $request, FulfillmentScan $scan): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'resi'])['code']);

        try {
            $order = $scan->handover($code, $request->user()->id);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first();
            $already = SalesOrder::findByScan($code);

            return response()->json([
                'message' => $message,
                // Sudah dikirim = tidak perlu diulang (mis. dikirim ulang dari antrean offline).
                'duplicate' => $already?->hasStatus('shipped') ?? false,
            ], 422);
        }

        $order->load(['courier', 'handover']);

        return response()->json([
            'message' => "{$order->number} · {$order->customer_name} dikirim.",
            'order' => [
                'number' => $order->number,
                'tracking' => $order->tracking_number,
                'customer' => $order->customer_name,
                'courier' => $order->courier?->name ?? 'Tanpa kurir',
                'handover_id' => $order->shipment_handover_id,
                'handover' => $order->handover?->number,
            ],
        ]);
    }

    public function show(ShipmentHandover $handover): View
    {
        $handover->load(['courier', 'warehouse', 'creator', 'orders']);

        return view('mobile.handover-sign', ['handover' => $handover]);
    }

    public function sign(Request $request, ShipmentHandover $handover): JsonResponse
    {
        $data = $request->validate([
            'receiver_name' => ['required', 'string', 'max:150'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2000000'],
        ], [
            'signature.required' => 'Minta kurir tanda tangan dulu.',
            'signature.starts_with' => 'Tanda tangan tidak terbaca, ulangi.',
        ], ['receiver_name' => 'nama kurir', 'signature' => 'tanda tangan']);

        $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        if ($png === false || ! str_starts_with($png, "\x89PNG")) {
            throw ValidationException::withMessages(['signature' => 'Tanda tangan tidak terbaca, ulangi.']);
        }

        $path = "handovers/{$handover->id}/ttd-".now()->format('YmdHis').'-'.Str::random(16).'.png';
        Storage::disk('public')->put($path, $png);

        $old = $handover->receiver_signature_path;
        $handover->update([
            'receiver_name' => $data['receiver_name'],
            'receiver_signature_path' => $path,
            'signed_at' => now(),
        ]);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'message' => "Manifest {$handover->number} ditandatangani {$data['receiver_name']}.",
            'manifest' => $this->manifestPayload($handover->fresh()),
        ]);
    }

    /** Isi manifest untuk dicetak di printer thermal dari HP. */
    public function manifest(ShipmentHandover $handover): JsonResponse
    {
        return response()->json(['manifest' => $this->manifestPayload($handover)]);
    }

    /** @return array<string, mixed> */
    private function manifestPayload(ShipmentHandover $handover): array
    {
        $handover->loadMissing(['courier', 'warehouse', 'creator', 'orders']);

        return [
            'number' => $handover->number,
            'warehouse' => $handover->warehouse?->name,
            'courier' => $handover->courier?->name ?? 'Tanpa kurir',
            'date' => $handover->created_at->translatedFormat('d M Y H:i'),
            'officer' => $handover->creator?->name,
            'receiver' => $handover->receiver_name,
            'signed_at' => $handover->signed_at?->translatedFormat('d M Y H:i'),
            'signature_url' => $handover->receiver_signature_path ? Storage::disk('public')->url($handover->receiver_signature_path) : null,
            'orders' => $handover->orders->map(fn ($order) => [
                'number' => $order->number,
                'tracking' => $order->tracking_number,
                'customer' => $order->customer_name,
            ])->values(),
        ];
    }
}
