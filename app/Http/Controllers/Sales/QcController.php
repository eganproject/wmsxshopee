<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\FulfillmentScan;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** QC kemas: scan resi lalu scan tiap barang sebelum paket ditutup. */
class QcController extends Controller
{
    /**
     * Antrean QC: pesanan Sedang Diambil, bisa disaring per gudang, kurir,
     * pickup / drop off dan kata kunci. Pilihan gudang diingat di session.
     */
    public function index(Request $request): View
    {
        $warehouses = Warehouse::orderBy('code')->get();

        if ($request->has('warehouse')) {
            $request->session()->put('qc_warehouse', (string) $request->input('warehouse'));
        }
        $warehouseId = (int) ($request->has('warehouse') ? $request->input('warehouse') : $request->session()->get('qc_warehouse', ''));
        if (! $warehouses->contains('id', $warehouseId)) {
            // Tanpa pilihan: user satu gudang langsung ke gudangnya, selain itu semua gudang.
            $warehouseId = $warehouses->count() === 1 ? $warehouses->first()->id : 0;
        }

        $courier = (string) $request->input('courier');
        $shipment = (string) $request->input('shipment');
        $search = trim((string) $request->input('search'));

        $base = SalesOrder::query()
            ->where('status', 'picking')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId));

        $queue = (clone $base)
            ->with(['warehouse', 'courier'])
            ->withSum('items', 'qty')
            ->when($courier === 'none', fn ($query) => $query->whereNull('courier_id'))
            ->when(ctype_digit($courier), fn ($query) => $query->where('courier_id', (int) $courier))
            ->when($shipment === 'none', fn ($query) => $query->whereNull('shipping_method'))
            ->when(isset(SalesOrder::SHIPPING_METHODS[$shipment]), fn ($query) => $query->where('shipping_method', $shipment))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('external_number', 'like', "%{$search}%")
                ->orWhere('tracking_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->oldest('picking_at')
            ->oldest('id')
            ->paginate(50)
            ->withQueryString();

        // Pilihan kurir hanya yang ada di antrean gudang terpilih.
        $couriers = Courier::whereIn('id', (clone $base)->whereNotNull('courier_id')->select('courier_id'))->orderBy('name')->get();

        return view('sales.qc.index', [
            'queue' => $queue,
            'warehouses' => $warehouses,
            'warehouseId' => $warehouseId,
            'couriers' => $couriers,
            'waitingHours' => max(1, (int) config('alerts.qc_waiting_hours')),
            'filtered' => $courier !== '' || $shipment !== '' || $search !== '',
        ]);
    }

    /** Hasil scan resi / no. SO di halaman QC. */
    public function lookup(Request $request): RedirectResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'resi'])['code']);
        $order = SalesOrder::findByScan($code);

        if (! $order) {
            return back()->with('error', "Resi / pesanan \"{$code}\" tidak ditemukan.");
        }

        if (! $order->hasStatus('picking')) {
            return back()->with('error', "{$order->number} berstatus {$order->statusLabel()}. QC hanya untuk pesanan yang Sedang Diambil (picking selesai).");
        }

        return redirect()->route('sales.qc.show', $order);
    }

    public function show(SalesOrder $order, FulfillmentScan $scan): View|RedirectResponse
    {
        if (! $order->hasStatus('picking')) {
            return redirect()->route('sales.qc.index')->with('error', "{$order->number} berstatus {$order->statusLabel()}, tidak perlu QC.");
        }

        $order->load(['warehouse', 'courier']);

        return view('sales.qc.show', ['order' => $order, 'expected' => $scan->expected($order)]);
    }

    public function store(Request $request, SalesOrder $order, FulfillmentScan $scan): RedirectResponse
    {
        $data = $request->validate([
            'scanned' => ['required', 'array'],
            'scanned.*' => ['integer', 'min:0'],
        ]);

        Activity::track($order, fn () => $scan->completeQc($order, $data['scanned'], $request->user()->id));

        return redirect()->route('sales.qc.index')->with('success', "QC {$order->number} cocok. Pesanan Sudah Dikemas dan siap diserahkan ke kurir.");
    }
}
