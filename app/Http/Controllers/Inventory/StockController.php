<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use App\Services\WarehouseSupply;
use App\Support\StockOverview;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockController extends Controller
{
    /**
     * Stok per SKU (fisik, dipesan, tersedia, tertahan) atau saldo per lokasi bin.
     * Halaman ini hanya membaca; tidak mengubah stok maupun pesanan.
     */
    public function index(Request $request): View|BinaryFileResponse
    {
        return match ($request->input('view')) {
            'location' => $this->byLocation($request),
            'bundle' => $this->byBundle($request),
            default => $this->byVariant($request),
        };
    }

    /**
     * Stok virtual produk paket: paket tidak punya stok sendiri, jumlahnya
     * dihitung dari stok tersedia barang isinya (yang paling sedikit menentukan).
     * Tanpa filter gudang, angka per gudang dijumlah (paket tidak bisa dirakit
     * dari barang di dua gudang berbeda).
     */
    private function byBundle(Request $request): View|BinaryFileResponse
    {
        $warehouses = Warehouse::orderBy('code')->get();
        $selected = $request->filled('warehouse') ? $warehouses->where('id', $request->integer('warehouse')) : $warehouses->where('is_active', true);
        $warehouseId = $request->filled('warehouse') ? $request->integer('warehouse') : null;

        $query = Bundle::query()
            ->with('items.variant.product.unit')
            ->whereHas('items')
            ->when(! $request->boolean('zero'), fn ($query) => $query->where('is_active', true))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', $search)
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('items.variant', fn ($v) => $v->where('sku', 'like', "%{$search}%")));
            })
            ->orderBy('sku');

        // Per paket: tersedia (bisa dibuat), dipesan, stok jual, plus tersedia per barang isi.
        $levels = function (Collection $bundles) use ($selected, $warehouseId) {
            $variantIds = $bundles->flatMap(fn (Bundle $b) => $b->items->pluck('product_variant_id'))->unique()->values()->all();
            $components = collect();
            $available = collect();

            foreach ($selected as $warehouse) {
                $stock = app(SalesOrderService::class)->availability($warehouse->id, $variantIds);
                foreach ($stock as $id => $row) {
                    $components[$id] = ($components[$id] ?? 0) + $row['available'];
                }
                foreach ($bundles as $bundle) {
                    $available[$bundle->id] = ($available[$bundle->id] ?? 0) + (int) max(0, $bundle->items->min(
                        fn ($item) => intdiv(max(0, $stock[$item->product_variant_id]['available'] ?? 0), max(1, $item->qty))
                    ));
                }
            }

            $reserved = SalesOrder::query()
                ->join('sales_order_bundles', 'sales_order_bundles.sales_order_id', '=', 'sales_orders.id')
                ->whereIn('sales_orders.status', SalesOrder::RESERVING)
                ->whereIn('sales_orders.warehouse_id', $selected->pluck('id'))
                ->whereIn('sales_order_bundles.bundle_id', $bundles->pluck('id'))
                ->groupBy('sales_order_bundles.bundle_id')
                ->selectRaw('sales_order_bundles.bundle_id, SUM(sales_order_bundles.qty) as qty')
                ->pluck('qty', 'bundle_id');

            return [
                'available' => $available,
                'reserved' => $reserved->map(fn ($qty) => (int) $qty),
                'components' => $components,
                'sellable' => $warehouseId ? app(WarehouseSupply::class)->bundleSellable($warehouseId, $bundles) : collect(),
            ];
        };

        if ($request->input('export') === 'xlsx') {
            $bundles = $query->get();
            $data = $levels($bundles);

            return XlsxExport::download('stok-paket-virtual-'.now()->format('Ymd-His').'.xlsx',
                ['SKU Paket', 'Nama Paket', 'Jenis', 'Isi Paket', 'Dipesan', 'Tersedia', ...($warehouseId ? ['Stok Jual'] : []), 'Status'],
                $bundles->map(fn (Bundle $b) => [
                    $b->sku, $b->name, 'Virtual',
                    $b->items->map(fn ($item) => $item->variant?->sku.' × '.$item->qty)->implode(', '),
                    (int) ($data['reserved'][$b->id] ?? 0), (int) ($data['available'][$b->id] ?? 0),
                    ...($warehouseId ? [(int) ($data['sellable'][$b->id] ?? 0)] : []),
                    $b->is_active ? 'Aktif' : 'Nonaktif',
                ]));
        }

        $stocks = $query->paginate(25)->withQueryString();

        return view('inventory.stocks.index', [
            'stocks' => $stocks,
            'mode' => 'bundle',
            'warehouses' => $warehouses,
            'filter' => null,
            'bundleLevels' => $levels($stocks->getCollection()),
            'sellable' => collect(),
            'picked' => collect(),
        ]);
    }

    private function byVariant(Request $request): View|BinaryFileResponse
    {
        $warehouseId = $request->filled('warehouse') ? $request->integer('warehouse') : null;
        $filter = array_key_exists((string) $request->input('status'), StockOverview::FILTERS) ? $request->input('status') : null;

        $query = StockOverview::query($warehouseId, $request->boolean('zero'), $filter)
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q
                    ->where('product_variants.sku', 'like', "%{$search}%")
                    ->orWhere('product_variants.barcode', $search)
                    ->orWhere('products.name', 'like', "%{$search}%"));
            });

        $export = $request->input('export') === 'xlsx';
        $totals = $export ? null : StockOverview::totals(clone $query);

        $query->with('product.unit')->orderBy('product_variants.sku');

        $sellable = fn (array $ids) => $warehouseId && $ids
            ? app(WarehouseSupply::class)->sellable($warehouseId, $ids)->map(fn ($row) => $row['sellable'])
            : collect();

        if ($export) {
            $rows = $query->get();
            $levels = $sellable($rows->pluck('id')->all());

            return XlsxExport::download('stok-per-sku-'.now()->format('Ymd-His').'.xlsx',
                ['SKU', 'Produk', 'Varian', 'Satuan', 'Fisik', 'Dipesan', 'Tersedia', 'Tertahan (Draft)', ...($warehouseId ? ['Stok Jual'] : []), 'Stok Minimum', 'Status', 'Jumlah Lokasi'],
                $rows->map(fn ($v) => [
                    $v->sku, $v->product?->name, $v->name, $v->product?->unit?->code,
                    (int) $v->on_hand, (int) $v->reserved, (int) $v->available, (int) $v->held,
                    ...($warehouseId ? [(int) ($levels[$v->id] ?? 0)] : []),
                    $v->minimum === null ? null : (int) $v->minimum,
                    StockOverview::STATUSES[StockOverview::status($v)]['label'],
                    (int) $v->location_count,
                ]));
        }

        $stocks = $query->paginate(25)->withQueryString();

        return view('inventory.stocks.index', [
            'stocks' => $stocks,
            'mode' => 'variant',
            'warehouses' => Warehouse::orderBy('code')->get(),
            'totals' => $totals,
            'filter' => $filter,
            'sellable' => $sellable($stocks->pluck('id')->all()),
            'picked' => collect(),
        ]);
    }

    private function byLocation(Request $request): View|BinaryFileResponse
    {
        $warehouseId = $request->filled('warehouse') ? $request->integer('warehouse') : null;

        $query = Stock::query()
            ->when(! $request->boolean('zero'), fn ($query) => $query->where('stocks.qty', '!=', 0))
            ->when($warehouseId, fn ($query) => $query->where('stocks.warehouse_id', $warehouseId))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q
                    ->whereHas('variant', fn ($v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))
                    ->orWhereHas('location', fn ($l) => $l->where('code', 'like', "%{$search}%")));
            });

        $export = $request->input('export') === 'xlsx';
        $totalQty = $export ? 0 : (int) (clone $query)->sum('stocks.qty');
        $picked = StockOverview::pickedByLocation($warehouseId);
        $pickedQty = fn (Stock $s) => (int) ($picked[StockOverview::locationKey($s->product_variant_id, $s->warehouse_id, $s->warehouse_location_id)] ?? 0);

        $query
            ->with(['variant.product.unit', 'warehouse', 'location'])
            ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'stocks.warehouse_location_id')
            ->orderBy('stocks.warehouse_id')
            ->orderBy('warehouse_locations.code')
            ->orderBy('stocks.id')
            ->select('stocks.*');

        if ($export) {
            return XlsxExport::download('stok-per-lokasi-'.now()->format('Ymd-His').'.xlsx',
                ['Gudang', 'Lokasi', 'SKU', 'Barcode', 'Produk', 'Varian', 'Satuan', 'Fisik', 'Sudah Diambil', 'Bebas'],
                $query->lazy(500)->map(fn ($s) => [
                    $s->warehouse?->code, $s->location?->code ?? 'Tanpa bin', $s->variant?->sku, $s->variant?->barcode, $s->variant?->product?->name,
                    $s->variant?->name, $s->variant?->product?->unit?->code, (int) $s->qty, $pickedQty($s), (int) $s->qty - $pickedQty($s),
                ]));
        }

        $stocks = $query->paginate(25)->withQueryString();

        return view('inventory.stocks.index', [
            'stocks' => $stocks,
            'mode' => 'location',
            'warehouses' => Warehouse::orderBy('code')->get(),
            'totalQty' => $totalQty,
            'filter' => null,
            'sellable' => collect(),
            'picked' => $stocks->getCollection()->mapWithKeys(fn (Stock $s) => [$s->id => $pickedQty($s)]),
        ]);
    }
}
