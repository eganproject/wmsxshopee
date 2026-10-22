<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\WarehouseSupply;
use App\Support\StockOverview;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
        $mode = $request->input('view') === 'location' ? 'location' : 'variant';

        return $mode === 'variant' ? $this->byVariant($request) : $this->byLocation($request);
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
