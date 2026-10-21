<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Rules\AccessibleRecord;
use App\Services\FixedBins;
use App\Services\WarehouseSupply;
use App\Services\WarehouseTransferService;
use App\Support\WarehouseAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Transfer Antar Gudang (cth. gudang besar -> gudang kecil) beserta saran isi
 * ulang untuk gudang yang punya gudang cadangan.
 */
class WarehouseTransferController extends Controller
{
    public function index(Request $request, WarehouseSupply $supply): View
    {
        $transfers = WarehouseTransfer::query()
            ->with(['fromWarehouse' => fn ($q) => $q->withoutGlobalScope('warehouse_access'), 'toWarehouse' => fn ($q) => $q->withoutGlobalScope('warehouse_access'), 'creator'])
            ->withSum('items', 'qty')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('warehouse'), fn ($q) => $q->where(fn ($q) => $q->where('from_warehouse_id', $request->integer('warehouse'))->orWhere('to_warehouse_id', $request->integer('warehouse'))))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$request->string('search').'%')->orWhere('reference', 'like', '%'.$request->string('search').'%')))
            ->latest('transfer_date')->latest('id')
            ->paginate(20)->withQueryString();

        // Saran isi ulang untuk tiap gudang (yang bisa diakses) yang punya gudang cadangan.
        $suggestions = Warehouse::whereNotNull('supply_warehouse_id')->where('is_active', true)->orderBy('code')->get()
            ->map(fn (Warehouse $warehouse) => ['warehouse' => $warehouse, 'rows' => $supply->suggestions($warehouse)])
            ->filter(fn ($group) => $group['rows']->isNotEmpty())
            ->values();

        return view('inventory.transfers.index', [
            'transfers' => $transfers,
            'suggestions' => $suggestions,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'statuses' => WarehouseTransfer::STATUSES,
        ]);
    }

    public function create(Request $request, WarehouseSupply $supply): View
    {
        $sources = Warehouse::where('is_active', true)->orderBy('code')->get();
        $destinations = Warehouse::withoutGlobalScope('warehouse_access')->where('is_active', true)->orderBy('code')->get();
        $items = [];
        $from = $request->integer('from') ?: null;
        $to = $request->integer('to') ?: null;

        // ?suggest={gudang kecil}: isi otomatis dari saran isi ulang.
        if ($request->filled('suggest') && ($target = Warehouse::find($request->integer('suggest'))) && $target->supply_warehouse_id) {
            $to = $target->id;
            $from = $target->supply_warehouse_id;
            $items = $this->prefill($from, $to, $supply->suggestions($target)->filter(fn ($row) => $row->qty > 0));
        }

        return view('inventory.transfers.create', [
            'sources' => $sources,
            'destinations' => $destinations,
            'from' => $from,
            'to' => $to,
            'prefill' => $items,
            'locations' => WarehouseLocation::withoutGlobalScope('warehouse_access')
                ->whereIn('warehouse_id', $destinations->pluck('id'))
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code', 'type', 'is_active'])
                ->map(fn ($l) => ['id' => $l->id, 'warehouse_id' => $l->warehouse_id, 'code' => $l->code, 'active' => $l->is_active])
                ->values(),
            // Lokasi tetap SKU di gudang tujuan: [gudang, varian, [[lokasi, sisa ruang], ...]] urut prioritas.
            'defaults' => app(FixedBins::class)->forForm($destinations->pluck('id')->all()),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->orderBy('sku')
                ->get()
                ->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'barcode' => $v->barcode, 'label' => $v->displayName(), 'per' => $v->pcs_per_carton])
                ->values(),
        ]);
    }

    public function store(Request $request, WarehouseTransferService $service): RedirectResponse
    {
        $data = $this->validated($request);

        $transfer = $service->send([
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'transfer_date' => $data['transfer_date'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], collect($data['items'])->map(fn ($row) => [
            'product_variant_id' => (int) $row['product_variant_id'],
            'from_location_id' => filled($row['from_location_id'] ?? null) ? (int) $row['from_location_id'] : null,
            'to_location_id' => filled($row['to_location_id'] ?? null) ? (int) $row['to_location_id'] : null,
            'qty' => (int) $row['qty'],
            'notes' => $row['notes'] ?? null,
        ])->all(), $request->user()->id);

        return redirect()->route('inventory.transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->number} dikirim. Stok tercatat dalam perjalanan sampai diterima.");
    }

    public function show(WarehouseTransfer $transfer): View
    {
        $transfer->load([
            'fromWarehouse' => fn ($q) => $q->withoutGlobalScope('warehouse_access'),
            'toWarehouse' => fn ($q) => $q->withoutGlobalScope('warehouse_access'),
            'creator', 'receiver', 'outMovement', 'inMovement', 'returnMovement',
            'items.variant.product.unit',
            'items.fromLocation' => fn ($q) => $q->withoutGlobalScope('warehouse_access'),
            'items.toLocation' => fn ($q) => $q->withoutGlobalScope('warehouse_access'),
        ]);

        $ids = WarehouseAccess::ids();

        return view('inventory.transfers.show', [
            'transfer' => $transfer,
            'canReceive' => $ids === null || in_array($transfer->to_warehouse_id, $ids, true),
            'canCancel' => $ids === null || in_array($transfer->from_warehouse_id, $ids, true),
            'locations' => WarehouseLocation::withoutGlobalScope('warehouse_access')->where('warehouse_id', $transfer->to_warehouse_id)
                ->where('is_active', true)->orderBy('code')->get(['id', 'code']),
        ]);
    }

    public function receive(Request $request, WarehouseTransfer $transfer, WarehouseTransferService $service): RedirectResponse
    {
        $ids = WarehouseAccess::ids();
        abort_unless($ids === null || in_array($transfer->to_warehouse_id, $ids, true), 403);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.received_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.to_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $transfer->to_warehouse_id)->where('is_active', true)],
        ], [
            'items.*.to_location_id.exists' => 'Lokasi tujuan harus lokasi aktif di gudang tujuan.',
        ], ['items.*.received_qty' => 'jumlah diterima', 'items.*.to_location_id' => 'lokasi tujuan']);

        $service->receive($transfer, collect($data['items'])->map(fn ($row) => [
            'received_qty' => (int) $row['received_qty'],
            'to_location_id' => filled($row['to_location_id'] ?? null) ? (int) $row['to_location_id'] : null,
        ])->all(), $request->user()->id);

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', "Transfer {$transfer->number} diterima, stok masuk ke gudang tujuan.");
    }

    public function cancel(Request $request, WarehouseTransfer $transfer, WarehouseTransferService $service): RedirectResponse
    {
        $ids = WarehouseAccess::ids();
        abort_unless($ids === null || in_array($transfer->from_warehouse_id, $ids, true), 403);

        $data = $request->validate(['cancel_reason' => ['nullable', 'string', 'max:255']]);
        $service->cancel($transfer, $data['cancel_reason'] ?? null, $request->user()->id);

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', "Transfer {$transfer->number} dibatalkan, stok kembali ke gudang asal.");
    }

    /**
     * Baris awal dari saran: lokasi asal = bin dengan stok terbanyak di gudang
     * cadangan, lokasi tujuan = lokasi tetap SKU di gudang tujuan (bin utama
     * sampai penuh, sisanya ke bin cadangan).
     *
     * @return list<array<string, mixed>>
     */
    private function prefill(int $from, int $to, $rows): array
    {
        $ids = $rows->pluck('variant.id')->all();
        $bins = Stock::withoutGlobalScope('warehouse_access')->where('warehouse_id', $from)->whereIn('product_variant_id', $ids)->where('qty', '>', 0)
            ->orderByDesc('qty')->get()->groupBy('product_variant_id');
        $fixed = app(FixedBins::class);
        $targets = $fixed->for([$to], $ids);

        return $rows->flatMap(function ($row) use ($bins, $fixed, $targets, $to) {
            $split = $fixed->split($targets[$to.':'.$row->variant->id] ?? [], (int) $row->qty) ?: [['location_id' => null, 'qty' => (int) $row->qty]];

            return collect($split)->map(fn ($part) => [
                'product_variant_id' => (string) $row->variant->id,
                'from_location_id' => (string) ($bins[$row->variant->id][0]->warehouse_location_id ?? ''),
                'to_location_id' => (string) ($part['location_id'] ?? ''),
                'qty' => $part['qty'],
                'notes' => $row->urgent ? 'Untuk pesanan yang menunggu' : '',
            ]);
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $from = $request->integer('from_warehouse_id');
        $to = $request->integer('to_warehouse_id');

        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), new AccessibleRecord(Warehouse::class)],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.from_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $from)],
            'items.*.to_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $to)->where('is_active', true)],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'to_warehouse_id.different' => 'Gudang tujuan harus berbeda dari gudang asal.',
            'transfer_date.before_or_equal' => 'Tanggal kirim tidak boleh di masa depan.',
            'items.*.from_location_id.exists' => 'Lokasi asal harus berada di gudang asal.',
            'items.*.to_location_id.exists' => 'Lokasi tujuan harus lokasi aktif di gudang tujuan.',
        ], [
            'from_warehouse_id' => 'gudang asal',
            'to_warehouse_id' => 'gudang tujuan',
            'transfer_date' => 'tanggal kirim',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.from_location_id' => 'lokasi asal',
            'items.*.to_location_id' => 'lokasi tujuan',
            'items.*.qty' => 'jumlah',
            'items.*.notes' => 'keterangan',
        ]);

        // Satu SKU boleh beberapa baris dari bin asal yang sama asal bin tujuannya beda (bin utama & cadangan).
        if (collect($data['items'])->map(fn ($r) => $r['product_variant_id'].':'.($r['from_location_id'] ?? '').':'.($r['to_location_id'] ?? ''))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'SKU yang sama dari lokasi asal ke lokasi tujuan yang sama ditulis lebih dari sekali; gabungkan jadi satu baris.']);
        }

        return $data;
    }
}
