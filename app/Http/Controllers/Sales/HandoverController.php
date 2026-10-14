<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\ShipmentHandover;
use App\Services\FulfillmentScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Serah terima paket ke ekspedisi: cukup scan resi / no. pesanan. Tiap scan langsung
 * mengirim pesanan dan otomatis masuk manifest harian per gudang & kurir.
 */
class HandoverController extends Controller
{
    public function index(Request $request): View
    {
        ShipmentHandover::closeStale();

        $handovers = ShipmentHandover::query()
            ->with(['warehouse', 'courier'])
            ->withCount('orders')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $ready = SalesOrder::query()
            ->with('courier')
            ->where('status', 'packed')
            ->where('delivery_type', 'courier')
            ->get(['id', 'warehouse_id', 'courier_id'])
            ->groupBy(fn ($order) => $order->courier?->name ?? 'Tanpa kurir')
            ->map->count();

        $recent = SalesOrder::query()
            ->with('courier')
            ->whereNotNull('shipment_handover_id')
            ->where('shipped_at', '>=', today())
            ->latest('shipped_at')
            ->latest('id')
            ->limit(15)
            ->get();

        return view('sales.handovers.index', [
            'handovers' => $handovers,
            'ready' => $ready,
            'recent' => $recent,
            'todayCount' => SalesOrder::whereNotNull('shipment_handover_id')->where('shipped_at', '>=', today())->count(),
        ]);
    }

    public function scan(Request $request, FulfillmentScan $scan): RedirectResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'resi'])['code']);

        try {
            $order = $scan->handover($code, $request->user()->id);
        } catch (ValidationException $e) {
            // Umpan balik tampil di bawah kolom scan, bukan toast, supaya tidak menumpuk saat scan beruntun.
            return back()->with('scan_error', collect($e->errors())->flatten()->first());
        }

        return back()->with('scan_ok', "{$order->number} · {$order->customer_name} dikirim".($order->tracking_number ? " (resi {$order->tracking_number})" : '').'.');
    }

    public function show(ShipmentHandover $handover): View
    {
        $handover->load(['warehouse', 'courier', 'creator', 'orders.courier']);

        return view('sales.handovers.show', ['handover' => $handover]);
    }

    public function manifest(ShipmentHandover $handover): View
    {
        $handover->load(['warehouse', 'courier', 'creator', 'orders.courier', 'orders.items']);

        return view('sales.handovers.manifest', ['handover' => $handover]);
    }
}
