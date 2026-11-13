<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\ScanDuplicateAttempt;
use App\Models\Warehouse;
use App\Services\FulfillmentScan;
use App\Services\QcControl;
use App\Support\Activity;
use App\Support\Barcodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $held = $request->input('hold') === '1';

        $base = SalesOrder::query()
            ->where('status', 'picking')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId));

        $heldCount = (clone $base)->whereNotNull('qc_held_at')->count();

        $queue = (clone $base)
            ->with(['warehouse', 'courier', 'qcHolder'])
            ->when($held, fn ($query) => $query->whereNotNull('qc_held_at'))
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
            'filtered' => $courier !== '' || $shipment !== '' || $search !== '' || $held,
            'held' => $held,
            'heldCount' => $heldCount,
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

        if ($order->hasStatus('packed', 'shipped')) {
            ScanDuplicateAttempt::record($order, $code, 'qc', $request->user()->id);

            return back()->with('error', "Scan ganda: {$order->number} sudah lolos QC ({$order->statusLabel()}). Jangan kemas paket kedua.");
        }

        if (! $order->hasStatus('picking')) {
            return back()->with('error', "{$order->number} berstatus {$order->statusLabel()}. QC hanya untuk pesanan yang Sedang Diambil (picking selesai).");
        }

        if ($order->cancelRequested()) {
            return back()->with('error', "{$order->number}: pembeli mengajukan pembatalan di ".($order->isTiktok() ? 'TikTok' : 'Shopee').'. Jangan dikemas, sisihkan barangnya sampai ada keputusan di Seller Center.');
        }

        return redirect()->route('sales.qc.show', $order);
    }

    public function show(SalesOrder $order, FulfillmentScan $scan): View|RedirectResponse
    {
        if (! $order->hasStatus('picking')) {
            return redirect()->route('sales.qc.index')->with('error', "{$order->number} berstatus {$order->statusLabel()}, tidak perlu QC.");
        }

        $order->load(['warehouse', 'courier', 'qcHolder', 'qcSubstitutions.fromVariant', 'qcSubstitutions.toVariant', 'qcSubstitutions.user']);

        return view('sales.qc.show', [
            'order' => $order,
            'expected' => $scan->expected($order),
            // Hasil scan sebelum halaman dimuat ulang (setelah ganti SKU), supaya tidak scan dari awal.
            'restored' => (array) session('qc_scanned', []),
        ]);
    }

    /** Tahan QC dengan alasan; pesanan tidak bisa di-QC sampai dilepas. */
    public function hold(Request $request, SalesOrder $order, QcControl $qc): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $qc->hold($order, $data['reason'], $request->user()->id);

        return redirect()->route('sales.qc.index')->with('success', "QC {$order->number} ditahan: {$data['reason']}.");
    }

    public function release(SalesOrder $order, QcControl $qc): RedirectResponse
    {
        $qc->release($order);

        return redirect()->route('sales.qc.show', $order)->with('success', "Tahanan QC {$order->number} dilepas. Lanjutkan scan barang.");
    }

    /** Ganti barang pesanan dengan SKU lain (scan / ketik SKU pengganti), dengan alasan. */
    public function substitute(Request $request, SalesOrder $order, QcControl $qc): RedirectResponse
    {
        $data = $request->validate([
            'from_variant_id' => ['required', 'integer'],
            'to_code' => ['required', 'string', 'max:100'],
            'qty' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'scanned' => ['nullable', 'array'],
            'scanned.*' => ['integer', 'min:0'],
        ], [], ['from_variant_id' => 'barang asal', 'to_code' => 'SKU pengganti', 'qty' => 'jumlah', 'reason' => 'alasan']);

        $to = ProductVariant::findByCode($data['to_code']);
        if (! $to) {
            Barcodes::recordMiss($data['to_code'], 'qc', $request->user()->id, $order->warehouse_id, $order->id);

            throw ValidationException::withMessages(['to_code' => "SKU / barcode {$data['to_code']} tidak ditemukan."]);
        }

        $qc->substitute($order, (int) $data['from_variant_id'], $to->id, (int) $data['qty'], $data['reason'], $request->user()->id);

        // Hasil scan barang lain dipertahankan; barang asal dihitung ulang sesuai jumlah barunya.
        $scanned = collect($data['scanned'] ?? [])->map(fn ($qty) => (int) $qty);
        $scanned->forget((int) $data['from_variant_id']);

        return redirect()->route('sales.qc.show', $order)
            ->with('qc_scanned', $scanned->all())
            ->with('success', "{$data['qty']} barang diganti {$to->sku}. Scan barang pengganti untuk melanjutkan QC.");
    }

    public function store(Request $request, SalesOrder $order, FulfillmentScan $scan): RedirectResponse
    {
        $data = $request->validate([
            'scanned' => ['present', 'array'],
            'scanned.*' => ['integer', 'min:0'],
        ]);

        Activity::track($order, fn () => $scan->completeQc($order, $data['scanned'], $request->user()->id));

        return redirect()->route('sales.qc.index')->with('success', "QC {$order->number} cocok. Pesanan Sudah Dikemas dan siap diserahkan ke kurir.");
    }
}
