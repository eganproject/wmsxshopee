<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\FulfillmentScan;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** QC kemas: scan resi lalu scan tiap barang sebelum paket ditutup. */
class QcController extends Controller
{
    public function index(): View
    {
        $queue = SalesOrder::query()
            ->with(['warehouse', 'courier'])
            ->withSum('items', 'qty')
            ->where('status', 'picking')
            ->oldest('picking_at')
            ->limit(50)
            ->get();

        return view('sales.qc.index', ['queue' => $queue]);
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
