<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\DamagedDocument;
use App\Models\DamagedStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Rules\AccessibleRecord;
use App\Services\DamagedStockService;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dokumen alokasi barang rusak. Disimpan langsung dan tidak bisa diubah;
 * hak buat dicek per jenis (lihat DamagedDocument::TYPES).
 */
class DamagedDocumentController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = DamagedDocument::query()
            ->with(['warehouse', 'creator', 'supplier'])
            ->withCount('items')
            ->withSum('items', 'qty')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('document_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('document_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('recipient', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")));
            })
            ->latest('document_date')
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('dokumen-barang-rusak-'.now()->format('Ymd-His').'.xlsx',
                ['No. Dokumen', 'Tanggal', 'Jenis', 'Gudang', 'Penerima', 'Referensi', 'Jumlah Baris', 'Total Qty', 'Dibuat Oleh', 'Catatan'],
                $query->lazy(500)->map(fn ($d) => [
                    $d->number, $d->document_date->format('d/m/Y'), $d->typeLabel(), $d->warehouse?->code, $d->recipientName(), $d->reference,
                    $d->items_count, (int) $d->items_sum_qty, $d->creator?->name, $d->notes,
                ]));
        }

        return view('inventory.damaged.documents.index', [
            'documents' => $query->paginate(20)->withQueryString(),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'types' => DamagedDocument::TYPES,
        ]);
    }

    public function create(Request $request): View
    {
        $allowed = DamagedStockController::allowedTypes($request);
        abort_if($allowed === [], 403);

        $type = $request->query('type', array_key_first($allowed));
        $this->authorizeType($request, $type);

        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();

        return view('inventory.damaged.documents.create', [
            'type' => $type,
            'meta' => DamagedDocument::TYPES[$type],
            'warehouses' => $warehouses,
            'suppliers' => $type === 'supplier_return' ? Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'locations' => WarehouseLocation::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code', 'is_active'])
                ->map(fn ($location) => ['id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code, 'active' => $location->is_active])
                ->values(),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName()])
                ->values(),
            // Saldo rusak per gudang per varian, untuk info "tersedia" dan pilihan barang.
            'damaged' => DamagedStock::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->where('qty', '>', 0)
                ->get(['warehouse_id', 'product_variant_id', 'qty'])
                ->groupBy('warehouse_id')
                ->map(fn ($rows) => $rows->pluck('qty', 'product_variant_id')),
            'prefill' => [
                'warehouse' => $request->query('warehouse'),
                'variant' => $request->query('variant'),
            ],
        ]);
    }

    public function store(Request $request, DamagedStockService $damaged): RedirectResponse
    {
        $type = (string) $request->input('type');
        $this->authorizeType($request, $type);
        $data = $this->validated($request, $type);

        $document = $damaged->record([
            'type' => $type,
            'warehouse_id' => (int) $data['warehouse_id'],
            'document_date' => $data['document_date'],
            'supplier_id' => $type === 'supplier_return' && filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
            'recipient' => $type === 'supplier_return' && blank($data['supplier_id'] ?? null) ? ($data['recipient'] ?? null) : null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ], collect($data['items'])->map(function ($row) use ($type) {
            $role = $type === 'assembly' ? $row['role'] : 'input';
            $source = $type === 'assembly' && $role === 'input' ? $row['source'] : ($type === 'intake' ? 'good' : 'damaged');

            return [
                'role' => $role,
                'source' => $role === 'output' ? 'good' : $source,
                'product_variant_id' => (int) $row['product_variant_id'],
                // Lokasi bin opsional: kosong berarti stok baik "Tanpa bin" di level gudang.
                'from_location_id' => ($type === 'intake' || ($role === 'input' && $source === 'good')) && filled($row['from_location_id'] ?? null) ? (int) $row['from_location_id'] : null,
                'to_location_id' => ($type === 'repair' || $role === 'output') && filled($row['to_location_id'] ?? null) ? (int) $row['to_location_id'] : null,
                'qty' => (int) $row['qty'],
                'notes' => $row['notes'] ?? null,
            ];
        })->all());

        return redirect()->route('inventory.damaged.documents.show', $document)
            ->with('success', "{$document->typeLabel()} {$document->number} berhasil disimpan.");
    }

    public function show(DamagedDocument $document): View
    {
        $document->load(['warehouse', 'supplier', 'creator', 'movement', 'items.variant.product.unit', 'items.fromLocation', 'items.toLocation']);

        return view('inventory.damaged.documents.show', ['document' => $document]);
    }

    private function authorizeType(Request $request, string $type): void
    {
        abort_unless(isset(DamagedDocument::TYPES[$type]), 404);
        abort_unless($request->user()->can(DamagedDocument::TYPES[$type]['permission']), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $type): array
    {
        $warehouseId = $request->integer('warehouse_id');
        $location = fn () => Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), new AccessibleRecord(Warehouse::class)],
            'document_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_id' => $type === 'supplier_return' ? ['nullable', 'integer', Rule::exists('suppliers', 'id')] : ['prohibited'],
            'recipient' => $type === 'supplier_return' ? ['nullable', 'required_without:supplier_id', 'string', 'max:150'] : ['nullable'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.role' => $type === 'assembly' ? ['required', Rule::in(['input', 'output'])] : ['nullable'],
            'items.*.source' => $type === 'assembly' ? ['nullable', 'required_if:items.*.role,input', Rule::in(['damaged', 'good'])] : ['nullable'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            // Mengambil dari lokasi nonaktif tetap boleh (untuk mengosongkan); menaruh hanya ke lokasi aktif.
            'items.*.from_location_id' => ['nullable', 'integer', $location()],
            'items.*.to_location_id' => ['nullable', 'integer', $location()->where('is_active', true)],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'document_date.before_or_equal' => 'Tanggal dokumen tidak boleh di masa depan.',
            'recipient.required_without' => 'Pilih supplier atau isi nama penerima retur.',
            'items.*.to_location_id.exists' => 'Lokasi tujuan harus lokasi aktif di gudang yang dipilih.',
            'items.*.from_location_id.exists' => 'Lokasi asal harus berada di gudang yang dipilih.',
            'items.*.source.required_if' => 'Pilih asal bahan.',
        ], [
            'warehouse_id' => 'gudang',
            'document_date' => 'tanggal',
            'supplier_id' => 'supplier',
            'recipient' => 'penerima',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.from_location_id' => 'lokasi asal',
            'items.*.to_location_id' => 'lokasi tujuan',
            'items.*.qty' => 'jumlah',
            'items.*.notes' => 'keterangan',
        ]);

        if ($type === 'assembly') {
            $items = collect($data['items']);

            if (! $items->contains(fn ($row) => $row['role'] === 'input' && $row['source'] === 'damaged')) {
                throw ValidationException::withMessages(['items' => 'Bahan rakit harus berisi minimal satu barang dari stok rusak.']);
            }

            if (! $items->contains(fn ($row) => $row['role'] === 'output')) {
                throw ValidationException::withMessages(['items' => 'Isi minimal satu barang hasil rakit / campur.']);
            }
        }

        return $data;
    }
}
