<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseZone;
use App\Services\StockCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockCountController extends Controller
{
    public function index(Request $request): View
    {
        $counts = StockCount::query()
            ->with(['warehouse', 'zone', 'creator'])
            ->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('search'), fn ($query) => $query->where('number', 'like', '%'.$request->string('search').'%'))
            ->latest('count_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.counts.index', [
            'counts' => $counts,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function create(): View
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();

        return view('inventory.counts.create', [
            'warehouses' => $warehouses,
            'zones' => WarehouseZone::whereIn('warehouse_id', $warehouses->pluck('id'))->orderBy('code')->get(['id', 'warehouse_id', 'code', 'name'])
                ->map(fn ($zone) => ['id' => $zone->id, 'warehouse_id' => $zone->warehouse_id, 'text' => "{$zone->code} · {$zone->name}"])->values(),
        ]);
    }

    public function store(Request $request, StockCountService $service): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'scope' => ['required', Rule::in(array_keys(StockCount::SCOPES))],
            'warehouse_zone_id' => ['nullable', 'required_if:scope,zone', 'integer', Rule::exists('warehouse_zones', 'id')->where('warehouse_id', $request->integer('warehouse_id'))],
            'count_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'warehouse_zone_id.required_if' => 'Pilih zona yang akan dihitung.',
            'warehouse_zone_id.exists' => 'Zona harus berada di gudang yang dipilih.',
            'count_date.before_or_equal' => 'Tanggal opname tidak boleh di masa depan.',
        ], [
            'warehouse_id' => 'gudang',
            'scope' => 'cakupan',
            'warehouse_zone_id' => 'zona',
            'count_date' => 'tanggal',
            'notes' => 'catatan',
        ]);

        $count = $service->create([
            ...$data,
            'warehouse_zone_id' => $data['scope'] === 'zone' ? $data['warehouse_zone_id'] : null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('inventory.counts.show', $count)
            ->with('success', "Lembar opname {$count->number} dibuat dengan {$count->items()->count()} baris. Isi hasil hitung lalu posting.");
    }

    public function show(StockCount $count, StockCountService $service): View
    {
        $count->load(['warehouse', 'zone', 'creator', 'poster', 'movement', 'items.variant.product.unit', 'items.location', 'items.target']);

        if (! $count->isDraft()) {
            return view('inventory.counts.show', ['count' => $count]);
        }

        $current = $service->currentBalances($count);
        $locations = WarehouseLocation::query()
            ->with('rack:id,warehouse_zone_id')
            ->where('warehouse_id', $count->warehouse_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'warehouse_id', 'warehouse_rack_id']);

        return view('inventory.counts.edit', [
            'count' => $count,
            'lines' => $count->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->variant->sku,
                'barcode' => $item->variant->barcode,
                'label' => $item->variant->product->name.($item->variant->name ? ' / '.$item->variant->name : ''),
                'unit' => $item->variant->product->unit->code,
                'warehouse_location_id' => $item->warehouse_location_id,
                'location_code' => $item->location?->code,
                'system_qty' => $item->system_qty,
                'current_qty' => (int) ($current[$item->product_variant_id.':'.($item->warehouse_location_id ?? 'none')] ?? 0),
                'counted_qty' => $item->counted_qty,
                'target_location_id' => $item->target_location_id,
                'notes' => $item->notes,
            ])->values(),
            // Lokasi yang boleh ditambahkan sebagai baris baru (sesuai cakupan) & bin tujuan untuk stok tanpa bin.
            'scopeLocations' => $locations->filter(fn ($location) => $count->coversLocation($location))
                ->map(fn ($location) => ['id' => $location->id, 'code' => $location->code])->values(),
            'bins' => $locations->map(fn ($location) => ['id' => $location->id, 'code' => $location->code])->values(),
            'allowUnbinned' => $count->coversLocation(null),
            'variants' => ProductVariant::query()
                ->with('product:id,name,unit_id', 'product.unit:id,code')
                ->where('is_active', true)
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'label' => $variant->product->name.($variant->name ? ' / '.$variant->name : ''),
                    'unit' => $variant->product->unit?->code,
                ])
                ->values(),
        ]);
    }

    /** Simpan hasil hitung; action=post sekalian posting selisih ke stok. */
    public function update(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        $post = $request->input('action') === 'post';
        abort_if($post && ! $request->user()->can('opname.post'), 403);

        $lines = $this->validatedLines($request, $count);

        try {
            $service->save($count, $lines);
            if ($post) {
                $service->post($count, $request->user()->id);
            }
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.counts.show', $count));
        }

        return redirect()->route('inventory.counts.show', $count)->with('success', $post
            ? "Opname {$count->number} diposting; selisih sudah masuk ke stok."
            : "Hasil hitung {$count->number} disimpan.");
    }

    public function destroy(StockCount $count): RedirectResponse
    {
        abort_unless($count->isDraft(), 403, 'Opname yang sudah diposting tidak bisa dihapus.');

        $count->delete();

        return redirect()->route('inventory.counts.index')->with('success', "Lembar opname {$count->number} dihapus.");
    }

    /** Lembar hitung cetak; saldo sistem disembunyikan kecuali ?system=1 (hitung buta). */
    public function sheet(Request $request, StockCount $count): View
    {
        $count->load(['warehouse', 'zone', 'items.variant.product.unit', 'items.location']);

        return view('inventory.counts.sheet', ['count' => $count, 'showSystem' => $request->boolean('system')]);
    }

    /**
     * Baris dikirim sebagai satu JSON (payload) supaya lembar besar tidak
     * terpotong batas max_input_vars PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validatedLines(Request $request, StockCount $count): array
    {
        $payload = json_decode((string) $request->input('payload'), true);
        $location = fn () => Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $count->warehouse_id);

        $validator = Validator::make(['lines' => is_array($payload) ? $payload : null], [
            'lines' => ['present', 'array', 'max:5000'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['required_without:lines.*.id', 'nullable', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.warehouse_location_id' => ['nullable', 'integer', $location()],
            'lines.*.counted_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.target_location_id' => ['nullable', 'integer', $location()->where('is_active', true)],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.present' => 'Data lembar hitung tidak terbaca, muat ulang halaman.',
            'lines.*.product_variant_id.required_without' => 'Pilih barang.',
        ], [
            'lines.*.product_variant_id' => 'barang',
            'lines.*.warehouse_location_id' => 'lokasi',
            'lines.*.counted_qty' => 'hasil hitung',
            'lines.*.target_location_id' => 'bin tujuan',
            'lines.*.notes' => 'catatan',
        ]);

        $lines = $validator->validate()['lines'];

        foreach ($lines as $index => $line) {
            if (empty($line['id'])) {
                $locationModel = filled($line['warehouse_location_id'] ?? null) ? WarehouseLocation::with('rack')->find($line['warehouse_location_id']) : null;
                if (! $count->coversLocation($locationModel)) {
                    throw ValidationException::withMessages(["lines.{$index}.warehouse_location_id" => 'Lokasi di luar cakupan opname ini.']);
                }
            }
        }

        return $lines;
    }
}
