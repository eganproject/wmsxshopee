<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use App\Support\Barcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cetak label: label SKU untuk varian produk & produk paket, dan label lokasi bin.
 *
 * Halaman pengaturan menampung daftar label (dipilih dari halaman daftar atau
 * dicari di sini), lalu halaman cetak menyusun label sesuai ukuran kertas di
 * config/labels.php. Kunci item: "v-{id}" varian, "b-{id}" paket, "l-{id}" lokasi.
 */
class LabelController extends Controller
{
    public function products(Request $request): View
    {
        $variantIds = ProductVariant::query()
            ->whereIn('product_id', $this->ids($request, 'products'))
            ->pluck('id')
            ->merge($this->ids($request, 'variants'));

        $items = $this->variantItems($variantIds)
            ->merge($this->bundleItems($this->ids($request, 'bundles')))
            ->values();

        return view('labels.builder', [
            'kind' => 'products',
            'items' => $items,
            'sizes' => config('labels.sizes'),
            'defaultSize' => config('labels.default_size.products'),
        ]);
    }

    public function locations(Request $request): View
    {
        $query = WarehouseLocation::query();

        if ($request->boolean('all')) {
            // "Cetak semua hasil filter" dari halaman Lokasi Bin.
            $this->filterLocations($query, $request);
        } else {
            $query->whereIn('id', $this->ids($request, 'locations'));
        }

        return view('labels.builder', [
            'kind' => 'locations',
            'items' => $this->locationItems($query->limit(config('labels.max_labels'))),
            'sizes' => config('labels.sizes'),
            'defaultSize' => config('labels.default_size.locations'),
            'racks' => WarehouseRack::with('zone.warehouse')->orderBy('code')->get()
                ->sortBy(fn (WarehouseRack $rack) => $rack->zone->warehouse->code.' '.$rack->code)
                ->map(fn (WarehouseRack $rack) => ['value' => $rack->id, 'text' => "{$rack->zone->warehouse->code} · Zona {$rack->zone->code} · Rak {$rack->code}"])
                ->values(),
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    /** Cari varian & paket untuk ditambahkan ke daftar label. */
    public function productLookup(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        if ($search === '') {
            return response()->json([]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $variantIds = ProductVariant::query()
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
            ->orderBy('sku')
            ->limit(25)
            ->pluck('id');

        $bundleIds = Bundle::query()
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('name', 'like', $like))
            ->orderBy('sku')
            ->limit(10)
            ->pluck('id');

        return response()->json($this->asOptions($this->variantItems($variantIds)->merge($this->bundleItems($bundleIds))));
    }

    /** Cari lokasi untuk ditambahkan; dengan ?rack= mengembalikan semua lokasi di rak itu. */
    public function locationLookup(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());

        $query = WarehouseLocation::query();
        if ($request->filled('rack')) {
            $query->where('warehouse_rack_id', $request->integer('rack'));
        } elseif ($search !== '') {
            $query->where('code', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')->limit(30);
        } else {
            return response()->json([]);
        }

        return response()->json($this->asOptions($this->locationItems($query)));
    }

    public function printProducts(Request $request): View
    {
        $options = $request->validate($this->optionRules() + [
            'content' => ['required', Rule::in(['barcode', 'sku'])],
            'show_name' => ['boolean'],
            'show_variant' => ['boolean'],
            'show_sku' => ['boolean'],
        ]);

        $quantities = $this->quantities($request);
        $items = $this->variantItems($this->idsOfType($quantities, 'v'))
            ->merge($this->bundleItems($this->idsOfType($quantities, 'b')));

        $labels = $this->expand($items, $quantities, fn (array $item) => [
            ...$item,
            'value' => $options['content'] === 'sku' ? $item['sku'] : $item['code'],
        ]);

        return $this->sheet('products', $labels, $options, $request);
    }

    public function printLocations(Request $request): View
    {
        $options = $request->validate($this->optionRules() + [
            'show_path' => ['boolean'],
        ]);

        $quantities = $this->quantities($request);
        $items = $this->locationItems(WarehouseLocation::whereIn('id', $this->idsOfType($quantities, 'l')));

        $labels = $this->expand($items, $quantities, fn (array $item) => [...$item, 'value' => $item['code']]);

        return $this->sheet('locations', $labels, $options, $request);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $labels
     * @param  array<string, mixed>  $options
     */
    private function sheet(string $kind, Collection $labels, array $options, Request $request): View
    {
        $preview = $request->boolean('preview');
        $size = config("labels.sizes.{$options['size']}");

        if ($preview) {
            $labels = $labels->take(1);
        } elseif ($labels->isEmpty()) {
            throw ValidationException::withMessages(['qty' => 'Belum ada label yang dipilih untuk dicetak.']);
        }

        $symbol = $options['symbol'];
        $labels = $labels->map(fn (array $label) => [
            ...$label,
            'barcode' => $symbol !== 'qr' ? Barcode::code128($label['value']) : null,
            'qr' => $symbol !== 'barcode' ? Barcode::qr($label['value']) : null,
        ]);

        // Lembar A4: label pertama bisa digeser untuk memakai sisa stiker di lembar bekas.
        $offset = isset($size['sheet']) && ! $preview ? min((int) ($options['offset'] ?? 0), $size['sheet']['columns'] * $size['sheet']['rows'] - 1) : 0;
        $cells = collect(array_fill(0, $offset, null))->merge($labels);

        return view('labels.print', [
            'kind' => $kind,
            'size' => $size,
            'options' => $options,
            'preview' => $preview,
            'count' => $labels->count(),
            'pages' => isset($size['sheet']) ? $cells->chunk($size['sheet']['columns'] * $size['sheet']['rows']) : $cells->map(fn ($label) => collect([$label])),
            'scale' => $this->fontScale($size),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function optionRules(): array
    {
        return [
            'size' => ['required', Rule::in(array_keys(config('labels.sizes')))],
            'symbol' => ['required', Rule::in(['barcode', 'qr', 'both'])],
            'offset' => ['nullable', 'integer', 'min:0', 'max:200'],
            'preview' => ['boolean'],
        ];
    }

    /**
     * Jumlah salinan per item dari input qty[kunci] = jumlah.
     *
     * @return array<string, int>
     */
    private function quantities(Request $request): array
    {
        $quantities = collect((array) $request->input('qty', []))
            ->filter(fn ($qty, $key) => is_string($key) && preg_match('/^[vbl]-\d+$/', $key))
            ->map(fn ($qty) => max(0, min(999, (int) $qty)))
            ->filter();

        if ($quantities->sum() > config('labels.max_labels')) {
            throw ValidationException::withMessages([
                'qty' => 'Maksimal '.number_format(config('labels.max_labels'), 0, ',', '.').' label sekali cetak. Kurangi jumlah atau cetak bertahap.',
            ]);
        }

        return $quantities->all();
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array<int, int>
     */
    private function idsOfType(array $quantities, string $type): array
    {
        return collect(array_keys($quantities))
            ->filter(fn (string $key) => str_starts_with($key, "{$type}-"))
            ->map(fn (string $key) => (int) substr($key, 2))
            ->values()
            ->all();
    }

    /**
     * Ulangi tiap item sebanyak jumlahnya, dengan urutan seperti di daftar.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, int>  $quantities
     * @return Collection<int, array<string, mixed>>
     */
    private function expand(Collection $items, array $quantities, callable $map): Collection
    {
        $byKey = $items->keyBy('key');

        return collect($quantities)
            ->filter(fn (int $qty, string $key) => $byKey->has($key))
            ->flatMap(fn (int $qty, string $key) => array_fill(0, $qty, $map($byKey[$key])))
            ->values();
    }

    /** @return Collection<int, int> */
    private function ids(Request $request, string $name): Collection
    {
        return collect((array) $request->input($name, []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  iterable<int, int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function variantItems(iterable $ids): Collection
    {
        $ids = collect($ids)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return ProductVariant::query()
            ->with('product:id,name,sku')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (ProductVariant $variant) => [$variant->product->name, $variant->sort_order, $variant->id])
            ->map(fn (ProductVariant $variant) => [
                'key' => "v-{$variant->id}",
                'type' => 'Varian',
                'title' => $variant->product->name,
                'subtitle' => $variant->name,
                'sku' => $variant->sku,
                'barcode_value' => $variant->barcode,
                'code' => $variant->barcode ?: $variant->sku,
                'active' => $variant->is_active,
            ])
            ->values();
    }

    /**
     * @param  iterable<int, int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function bundleItems(iterable $ids): Collection
    {
        $ids = collect($ids)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Bundle::query()
            ->whereIn('id', $ids)
            ->orderBy('sku')
            ->get()
            ->map(fn (Bundle $bundle) => [
                'key' => "b-{$bundle->id}",
                'type' => 'Paket',
                'title' => $bundle->name,
                'subtitle' => 'Produk paket',
                'sku' => $bundle->sku,
                'barcode_value' => $bundle->barcode,
                'code' => $bundle->barcode ?: $bundle->sku,
                'active' => $bundle->is_active,
            ]);
    }

    /**
     * @param  Builder<WarehouseLocation>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function locationItems(Builder $query): Collection
    {
        return $query
            ->with(['warehouse:id,code,name', 'rack.zone'])
            ->get()
            ->sortBy(fn (WarehouseLocation $location) => [$location->warehouse->code, $location->code])
            ->map(fn (WarehouseLocation $location) => [
                'key' => "l-{$location->id}",
                'code' => $location->code,
                'warehouse' => $location->warehouse->code,
                'path' => collect([
                    "Gudang {$location->warehouse->code}",
                    $location->rack ? "Zona {$location->rack->zone->code}" : null,
                    $location->rack ? "Rak {$location->rack->code}" : null,
                ])->filter()->join(' · '),
                'detail' => collect([
                    $location->level ? "Level {$location->level}" : null,
                    $location->position ? "Posisi {$location->position}" : null,
                    $location->typeLabel(),
                ])->filter()->join(' · '),
                'active' => $location->is_active,
            ])
            ->values();
    }

    /** Filter yang sama dengan halaman Lokasi Bin. */
    private function filterLocations(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('rack'), fn ($query) => $query->where('warehouse_rack_id', $request->integer('rack')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->when($request->filled('search'), fn ($query) => $query->where('code', 'like', '%'.$request->string('search').'%'));
    }

    /**
     * Opsi Tom Select: { value, text } plus seluruh data item untuk ditambahkan ke daftar.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function asOptions(Collection $items): Collection
    {
        return $items->map(fn (array $item) => [
            'value' => $item['key'],
            'text' => isset($item['sku'])
                ? "{$item['sku']} · {$item['title']}".($item['subtitle'] && $item['type'] === 'Varian' ? " / {$item['subtitle']}" : '').($item['type'] === 'Paket' ? ' (paket)' : '')
                : "{$item['code']} · {$item['path']}",
            'item' => $item,
        ])->values();
    }

    /**
     * Skala huruf relatif ke label 50 × 30 mm, supaya isi label proporsional di ukuran lain.
     *
     * @param  array<string, mixed>  $size
     */
    private function fontScale(array $size): float
    {
        return round(max(0.7, min(1.9, min($size['width'] / 50, $size['height'] / 30))), 2);
    }
}
