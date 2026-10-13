<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\SalesOrder;
use App\Models\ShipmentHandover;
use App\Models\Warehouse;
use App\Rules\AccessibleRecord;
use App\Services\FulfillmentScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Serah terima paket ke ekspedisi lewat scan resi, dengan manifest cetak. */
class HandoverController extends Controller
{
    public function index(Request $request): View
    {
        $handovers = ShipmentHandover::query()
            ->with(['warehouse', 'courier', 'creator'])
            ->withCount('orders')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $ready = SalesOrder::query()
            ->with('courier')
            ->where('status', 'packed')
            ->get(['id', 'warehouse_id', 'courier_id', 'shipping_method'])
            ->groupBy(fn ($order) => $order->courier?->name ?? 'Tanpa kurir')
            ->map->count();

        return view('sales.handovers.index', [
            'handovers' => $handovers,
            'ready' => $ready,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(),
            'couriers' => Courier::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', new AccessibleRecord(Warehouse::class)],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('is_active', true)],
            'shipping_method' => ['nullable', Rule::in(array_keys(SalesOrder::SHIPPING_METHODS))],
        ], [], ['warehouse_id' => 'gudang', 'courier_id' => 'kurir', 'shipping_method' => 'cara kirim']);

        $handover = DB::transaction(fn () => ShipmentHandover::create([
            ...$data,
            'number' => ShipmentHandover::nextNumber(),
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('sales.handovers.show', $handover);
    }

    public function show(ShipmentHandover $handover): View
    {
        $handover->load(['warehouse', 'courier', 'creator', 'orders.courier', 'orders.items']);

        $waiting = $handover->isOpen()
            ? SalesOrder::query()
                ->where('status', 'packed')
                ->where('warehouse_id', $handover->warehouse_id)
                ->when($handover->courier_id, fn ($q) => $q->where(fn ($q) => $q->where('courier_id', $handover->courier_id)->orWhereNull('courier_id')))
                ->when($handover->shipping_method, fn ($q) => $q->where(fn ($q) => $q->where('shipping_method', $handover->shipping_method)->orWhereNull('shipping_method')))
                ->with('courier')
                ->orderBy('packed_at')
                ->limit(200)
                ->get()
            : collect();

        return view('sales.handovers.show', ['handover' => $handover, 'waiting' => $waiting]);
    }

    public function scan(Request $request, ShipmentHandover $handover, FulfillmentScan $scan): RedirectResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']], [], ['code' => 'resi'])['code']);

        try {
            $order = $scan->handover($handover, $code, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->with('scan_failed', true);
        }

        return back()->with('success', "{$order->number} · {$order->customer_name} diserahkan".($order->tracking_number ? " (resi {$order->tracking_number})" : '').'.');
    }

    public function close(Request $request, ShipmentHandover $handover): RedirectResponse
    {
        abort_unless($handover->isOpen(), 403, 'Serah terima sudah ditutup.');

        $data = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['receiver_name' => 'nama kurir penerima', 'notes' => 'catatan']);

        if ($handover->orders()->doesntExist()) {
            $handover->delete();

            return redirect()->route('sales.handovers.index')->with('success', 'Serah terima kosong dibatalkan.');
        }

        $handover->update([...$data, 'status' => 'closed', 'closed_at' => now()]);

        return redirect()->route('sales.handovers.show', $handover)->with('success', "Serah terima {$handover->number} ditutup. Cetak manifest untuk ditandatangani kurir.");
    }

    public function manifest(ShipmentHandover $handover): View
    {
        $handover->load(['warehouse', 'courier', 'creator', 'orders.courier', 'orders.items']);

        return view('sales.handovers.manifest', ['handover' => $handover]);
    }
}
