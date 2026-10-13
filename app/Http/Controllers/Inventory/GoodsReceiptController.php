<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $receipts = GoodsReceipt::query()
            ->with(['supplier', 'warehouse'])
            ->withCount('items')
            ->withSum('items', 'qty_received')
            ->withSum('items', 'qty_damaged')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('supplier'), fn ($query) => $query->where('supplier_id', $request->integer('supplier')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('receipt_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('receipt_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('supplier_reference', 'like', "%{$search}%"));
            })
            ->latest('receipt_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.receipts.index', [
            'receipts' => $receipts,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function create(): View
    {
        return view('inventory.receipts.create', [
            'receipt' => new GoodsReceipt(['receipt_date' => now()]),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
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

        return $this->afterSave($request, $receipt, $stock, 'disimpan sebagai draft');
    }

    public function show(GoodsReceipt $receipt): View
    {
        $receipt->load(['supplier', 'warehouse', 'creator', 'receiver', 'movement', 'items.variant.product.unit', 'items.location']);

        return view('inventory.receipts.show', ['receipt' => $receipt]);
    }

    public function edit(GoodsReceipt $receipt): View|RedirectResponse
    {
        if (! $receipt->isDraft()) {
            return redirect()->route('inventory.receipts.show', $receipt)->with('error', "{$receipt->number} sudah diterima dan tidak bisa diubah.");
        }

        $receipt->load('items');

        return view('inventory.receipts.edit', ['receipt' => $receipt, ...$this->formData($receipt)]);
    }

    public function update(Request $request, GoodsReceipt $receipt, StockService $stock): RedirectResponse
    {
        abort_unless($receipt->isDraft(), 403, 'Penerimaan yang sudah diterima tidak bisa diubah.');

        $data = $this->validated($request, $receipt);

        DB::transaction(function () use ($request, $receipt, $data) {
            $receipt->update($data['header']);
            $receipt->items()->delete();
            $receipt->items()->createMany($data['items']);

            if ($request->boolean('remove_attachment') || $request->hasFile('attachment')) {
                $old = $receipt->attachment_path;
                $receipt->update(['attachment_path' => null]);
                DB::afterCommit(fn () => $old && Storage::disk('public')->delete($old));
            }

            $this->storeAttachment($request, $receipt);
        });

        return $this->afterSave($request, $receipt, $stock, 'diperbarui');
    }

    public function receive(Request $request, GoodsReceipt $receipt, StockService $stock): RedirectResponse
    {
        $this->post($request, $receipt, $stock);

        return redirect()->route('inventory.receipts.show', $receipt)
            ->with('success', "Penerimaan {$receipt->number} diterima. Stok masuk lewat {$receipt->movement->number}.");
    }

    public function destroy(GoodsReceipt $receipt): RedirectResponse
    {
        if (! $receipt->isDraft()) {
            return back()->with('error', "{$receipt->number} sudah diterima sehingga tidak bisa dihapus.");
        }

        $receipt->delete();

        if ($receipt->attachment_path) {
            Storage::disk('public')->delete($receipt->attachment_path);
        }

        return redirect()->route('inventory.receipts.index')
            ->with('success', "Draft penerimaan {$receipt->number} berhasil dihapus.");
    }

    /** Tombol "Simpan & Terima" langsung memposting stok setelah draft tersimpan. */
    private function afterSave(Request $request, GoodsReceipt $receipt, StockService $stock, string $verb): RedirectResponse
    {
        if ($request->input('action') === 'receive') {
            abort_unless($request->user()->can('receipts.receive'), 403);

            try {
                $this->post($request, $receipt, $stock);
            } catch (ValidationException $e) {
                // Draft sudah tersimpan; kembalikan ke form edit dengan pesan kenapa belum bisa diterima.
                return redirect()->route('inventory.receipts.edit', $receipt)->withErrors($e->errors());
            }

            return redirect()->route('inventory.receipts.show', $receipt)
                ->with('success', "Penerimaan {$receipt->number} diterima. Stok masuk lewat {$receipt->movement->number}.");
        }

        return redirect()->route('inventory.receipts.show', $receipt)
            ->with('success', "Penerimaan {$receipt->number} berhasil {$verb}.");
    }

    /**
     * Ubah status jadi Diterima dan catat Barang Masuk untuk qty kondisi baik.
     *
     * @throws ValidationException
     */
    private function post(Request $request, GoodsReceipt $receipt, StockService $stock): void
    {
        DB::transaction(function () use ($request, $receipt, $stock) {
            $receipt = GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $receipt->isDraft()) {
                throw ValidationException::withMessages(['receipt' => "{$receipt->number} sudah diterima sebelumnya."]);
            }

            $receipt->load(['supplier', 'items.location']);
            $lines = $receipt->items->where('qty_received', '>', 0);

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Belum ada barang dengan jumlah diterima (kondisi baik) lebih dari 0.']);
            }

            $inactive = $lines->first(fn ($item) => $item->location && (! $item->location->is_active || $item->location->warehouse_id !== $receipt->warehouse_id));
            if ($inactive) {
                throw ValidationException::withMessages(['items' => "Lokasi {$inactive->location->code} sudah nonaktif atau bukan di gudang ini. Ubah lokasinya dulu."]);
            }

            $movement = $stock->record([
                'type' => 'in',
                'warehouse_id' => $receipt->warehouse_id,
                'movement_date' => $receipt->receipt_date->toDateString(),
                'reference' => $receipt->number,
                'notes' => trim("Penerimaan dari {$receipt->supplier->name}".($receipt->supplier_reference ? " · {$receipt->supplier_reference}" : '')),
                'created_by' => $request->user()->id,
            ], $lines->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'to_location_id' => $item->warehouse_location_id,
                'qty' => $item->qty_received,
                'notes' => $item->notes,
            ])->values()->all());

            $receipt->update([
                'status' => 'received',
                'stock_movement_id' => $movement->id,
                'received_by' => $request->user()->id,
                'received_at' => now(),
            ]);
        });

        $receipt->refresh()->load('movement');
    }

    private function storeAttachment(Request $request, GoodsReceipt $receipt): void
    {
        if ($request->hasFile('attachment')) {
            $receipt->update(['attachment_path' => $request->file('attachment')->store("receipts/{$receipt->id}", 'public')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?GoodsReceipt $receipt = null): array
    {
        $warehouses = Warehouse::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($receipt, fn ($q) => $q->orWhere('id', $receipt->warehouse_id)))
            ->orderBy('code')
            ->get();

        return [
            'suppliers' => Supplier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($receipt, fn ($q) => $q->orWhere('id', $receipt->supplier_id)))
                ->orderBy('name')
                ->get(),
            'warehouses' => $warehouses,
            'locations' => WarehouseLocation::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code'])
                ->map(fn ($location) => ['id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code])
                ->values(),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName()])
                ->values(),
        ];
    }

    /**
     * @return array{header: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, ?GoodsReceipt $receipt = null): array
    {
        $warehouseId = $request->integer('warehouse_id');
        $active = fn (string $table, ?int $current) => Rule::exists($table, 'id')
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)));

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', $active('suppliers', $receipt?->supplier_id)],
            'warehouse_id' => ['required', 'integer', $active('warehouses', $receipt?->warehouse_id)],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'remove_attachment' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.warehouse_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId)->where('is_active', true)],
            'items.*.qty_ordered' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.qty_received' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.qty_damaged' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'receipt_date.before_or_equal' => 'Tanggal terima tidak boleh di masa depan.',
            'items.*.warehouse_location_id.exists' => 'Lokasi bin harus lokasi aktif di gudang yang dipilih.',
            'supplier_id.exists' => 'Supplier tidak ditemukan atau nonaktif.',
        ], [
            'supplier_id' => 'supplier',
            'warehouse_id' => 'gudang',
            'receipt_date' => 'tanggal terima',
            'supplier_reference' => 'no. PO / surat jalan',
            'notes' => 'catatan',
            'attachment' => 'lampiran',
            'items.*.product_variant_id' => 'barang',
            'items.*.warehouse_location_id' => 'lokasi bin',
            'items.*.qty_ordered' => 'jumlah dipesan',
            'items.*.qty_received' => 'jumlah diterima',
            'items.*.qty_damaged' => 'jumlah rusak',
            'items.*.notes' => 'keterangan',
        ]);

        $items = collect($data['items'])->map(fn ($row) => [
            'product_variant_id' => (int) $row['product_variant_id'],
            'warehouse_location_id' => filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null,
            'qty_ordered' => isset($row['qty_ordered']) ? (int) $row['qty_ordered'] : null,
            'qty_received' => (int) $row['qty_received'],
            'qty_damaged' => (int) ($row['qty_damaged'] ?? 0),
            'notes' => $row['notes'] ?? null,
        ]);

        $empty = $items->search(fn ($row) => $row['qty_received'] + $row['qty_damaged'] === 0);
        if ($empty !== false) {
            throw ValidationException::withMessages(["items.{$empty}.qty_received" => 'Isi jumlah diterima atau rusak.']);
        }

        return [
            'header' => [
                'supplier_id' => (int) $data['supplier_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            'items' => $items->values()->all(),
        ];
    }
}
