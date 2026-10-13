<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockMovementController extends Controller
{
    public function index(Request $request): View
    {
        $movements = StockMovement::query()
            ->with(['warehouse', 'creator'])
            ->withCount('items')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('movement_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('movement_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"));
            })
            ->latest('movement_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.movements.index', [
            'movements' => $movements,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'types' => StockMovement::TYPES,
        ]);
    }

    public function create(Request $request): View
    {
        $type = $this->authorizedType($request, $request->query('type', 'in'));

        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();

        return view('inventory.movements.create', [
            'type' => $type,
            'meta' => StockMovement::TYPES[$type],
            'warehouses' => $warehouses,
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
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $type = $this->authorizedType($request, (string) $request->input('type'));
        $data = $this->validated($request, $type);

        $movement = $stock->record([
            'type' => $type,
            'warehouse_id' => $data['warehouse_id'],
            'movement_date' => $data['movement_date'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ], collect($data['items'])->map(fn ($row) => [
            'product_variant_id' => (int) $row['product_variant_id'],
            'from_location_id' => in_array($type, ['out', 'transfer']) ? (int) $row['from_location_id'] : null,
            'to_location_id' => $type !== 'out' ? (int) $row['to_location_id'] : null,
            'qty' => (int) $row['qty'],
            'notes' => $row['notes'] ?? null,
        ])->all());

        return redirect()->route('inventory.movements.show', $movement)
            ->with('success', "{$movement->typeLabel()} {$movement->number} berhasil disimpan.");
    }

    public function show(StockMovement $movement): View
    {
        $movement->load(['warehouse', 'creator', 'items.variant.product.unit', 'items.fromLocation', 'items.toLocation']);

        return view('inventory.movements.show', ['movement' => $movement]);
    }

    /** Penyesuaian butuh permission tersendiri; jenis lain cukup inventory.create. */
    private function authorizedType(Request $request, string $type): string
    {
        abort_unless(array_key_exists($type, StockMovement::TYPES), 404);
        abort_unless($request->user()->can($type === 'adjustment' ? 'inventory.adjust' : 'inventory.create'), 403);

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $type): array
    {
        $warehouseId = $request->integer('warehouse_id');
        $location = fn () => Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId);

        return $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            // Mengambil dari lokasi nonaktif tetap boleh (untuk mengosongkan); menaruh hanya ke lokasi aktif.
            'items.*.from_location_id' => in_array($type, ['out', 'transfer']) ? ['required', 'integer', $location()] : ['nullable'],
            'items.*.to_location_id' => $type !== 'out'
                ? ['required', 'integer', $location()->where('is_active', true), ...($type === 'transfer' ? ['different:items.*.from_location_id'] : [])]
                : ['nullable'],
            'items.*.qty' => $type === 'adjustment'
                ? ['required', 'integer', 'between:-1000000,1000000', 'not_in:0']
                : ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'movement_date.before_or_equal' => 'Tanggal mutasi tidak boleh di masa depan.',
            'items.*.to_location_id.different' => 'Lokasi tujuan harus berbeda dari lokasi asal.',
            'items.*.to_location_id.exists' => 'Lokasi tujuan harus lokasi aktif di gudang yang dipilih.',
            'items.*.from_location_id.exists' => 'Lokasi asal harus berada di gudang yang dipilih.',
            'items.*.qty.not_in' => 'Jumlah penyesuaian tidak boleh 0.',
        ], [
            'warehouse_id' => 'gudang',
            'movement_date' => 'tanggal',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.from_location_id' => 'lokasi asal',
            'items.*.to_location_id' => $type === 'adjustment' ? 'lokasi' : 'lokasi tujuan',
            'items.*.qty' => 'jumlah',
            'items.*.notes' => 'keterangan',
        ]);
    }
}
