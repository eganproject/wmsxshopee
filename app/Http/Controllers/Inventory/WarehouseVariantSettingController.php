<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseVariantSetting;
use App\Rules\AccessibleRecord;
use App\Services\FixedBins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lokasi tetap SKU (satu atau beberapa bin berurutan: utama lalu cadangan) dan
 * stok min/maks per gudang. Lokasi tetap disarankan saat transfer & penerimaan
 * dan didahulukan saat picking; min/maks dipakai saran isi ulang dari gudang cadangan.
 */
class WarehouseVariantSettingController extends Controller
{
    public function index(Request $request, FixedBins $fixed): View
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();
        $warehouse = $warehouses->firstWhere('id', $request->integer('warehouse')) ?? $warehouses->first();
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->string('search')->toString())).'%';

        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('barcode', $request->string('search')->trim())
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like))))
            ->when($warehouse && $request->input('filter') === 'unset', fn ($q) => $q->whereDoesntHave('warehouseSettings', fn ($s) => $s->where('warehouse_id', $warehouse->id)->whereNotNull('default_location_id')))
            ->when($warehouse && $request->input('filter') === 'stocked', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('warehouse_id', $warehouse->id)->where('qty', '>', 0)))
            ->orderBy('sku')
            ->paginate(50)
            ->withQueryString();

        $ids = $variants->pluck('id');

        return view('inventory.sku-settings.index', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'variants' => $variants,
            // Bin lokasi tetap per varian: [[lokasi, kapasitas], ...] urut prioritas.
            'fixedBins' => $warehouse ? $fixed->for([$warehouse->id], $ids->all())
                ->mapWithKeys(fn ($bins, $key) => [(int) explode(':', $key)[1] => $bins->map(fn ($b) => ['location_id' => (string) $b['location_id'], 'capacity' => $b['capacity'] ?? ''])->all()]) : collect(),
            'settings' => $warehouse ? WarehouseVariantSetting::where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $ids)->get()->keyBy('product_variant_id') : collect(),
            'stock' => $warehouse ? Stock::where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $ids)->groupBy('product_variant_id')
                ->selectRaw('product_variant_id, SUM(qty) as qty')->pluck('qty', 'product_variant_id') : collect(),
            'bins' => $warehouse ? Stock::with('location:id,code')->where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $ids)->where('qty', '>', 0)
                ->whereNotNull('warehouse_location_id')->get()->groupBy('product_variant_id')->map(fn ($rows) => $rows->pluck('location.code')->filter()->unique()->values()) : collect(),
            'locations' => $warehouse ? WarehouseLocation::where('warehouse_id', $warehouse->id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'type']) : collect(),
        ]);
    }

    public function update(Request $request, FixedBins $fixed): RedirectResponse
    {
        $warehouseId = $request->integer('warehouse_id');

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id'), new AccessibleRecord(Warehouse::class)],
            'settings' => ['required', 'array', 'max:500'],
            // default_location_id: format lama (satu bin), tetap diterima.
            'settings.*.default_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId)],
            'settings.*.bins' => ['nullable', 'array', 'max:10'],
            'settings.*.bins.*.location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId)],
            'settings.*.bins.*.capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'settings.*.min_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'settings.*.max_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [
            'settings.*.default_location_id.exists' => 'Lokasi tetap harus lokasi di gudang yang dipilih.',
            'settings.*.bins.*.location_id.exists' => 'Lokasi tetap harus lokasi di gudang yang dipilih.',
            'settings.*.bins.max' => 'Lokasi tetap maksimal 10 bin per SKU.',
        ], [
            'settings.*.bins.*.capacity' => 'kapasitas bin',
            'settings.*.min_qty' => 'stok minimum',
            'settings.*.max_qty' => 'stok maksimum',
        ]);

        $variantIds = ProductVariant::whereIn('id', array_keys($data['settings']))->pluck('id')->all();

        // Baris bin tanpa lokasi diabaikan; bin yang sama tidak boleh dua kali.
        $bins = collect($data['settings'])->map(fn ($row) => collect($row['bins'] ?? (filled($row['default_location_id'] ?? null) ? [['location_id' => $row['default_location_id']]] : []))
            ->filter(fn ($bin) => filled($bin['location_id'] ?? null))
            ->map(fn ($bin) => ['location_id' => (int) $bin['location_id'], 'capacity' => filled($bin['capacity'] ?? null) ? (int) $bin['capacity'] : null])
            ->values()->all());

        foreach ($bins as $variantId => $rows) {
            if (collect($rows)->pluck('location_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(["settings.{$variantId}.bins" => 'Bin lokasi tetap yang sama dipilih lebih dari sekali untuk satu SKU.']);
            }
        }

        foreach ($data['settings'] as $variantId => $row) {
            if (filled($row['min_qty'] ?? null) && filled($row['max_qty'] ?? null) && (int) $row['max_qty'] < (int) $row['min_qty']) {
                throw ValidationException::withMessages(["settings.{$variantId}.max_qty" => 'Stok maksimum tidak boleh lebih kecil dari stok minimum.']);
            }
        }

        foreach ($data['settings'] as $variantId => $row) {
            if (! in_array((int) $variantId, $variantIds, true)) {
                continue;
            }

            $values = [
                'default_location_id' => $bins[$variantId][0]['location_id'] ?? null,
                'min_qty' => filled($row['min_qty'] ?? null) ? (int) $row['min_qty'] : null,
                'max_qty' => filled($row['max_qty'] ?? null) ? (int) $row['max_qty'] : null,
            ];
            $match = ['warehouse_id' => $data['warehouse_id'], 'product_variant_id' => (int) $variantId];

            if (array_filter($values, fn ($v) => $v !== null) === []) {
                WarehouseVariantSetting::where($match)->delete();
            } else {
                WarehouseVariantSetting::updateOrCreate($match, $values);
            }

            $fixed->sync((int) $data['warehouse_id'], (int) $variantId, $bins[$variantId]);
        }

        return back()->with('success', 'Lokasi tetap & stok min/maks disimpan.');
    }
}
