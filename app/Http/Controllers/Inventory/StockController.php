<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\WarehouseSupply;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockController extends Controller
{
    /** Stok saat ini, per lokasi atau dijumlahkan per varian. */
    public function index(Request $request): View|BinaryFileResponse
    {
        $mode = $request->input('view') === 'variant' ? 'variant' : 'location';

        $query = Stock::query()
            ->when(! $request->boolean('zero'), fn ($query) => $query->where('stocks.qty', '!=', 0))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('stocks.warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('search'), function (Builder $query) use ($request, $mode) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q
                    ->whereHas('variant', fn ($v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))
                    ->when($mode === 'location', fn ($q) => $q->orWhereHas('location', fn ($l) => $l->where('code', 'like', "%{$search}%"))));
            });

        $export = $request->input('export') === 'xlsx';
        $totalQty = $export ? 0 : (int) (clone $query)->sum('stocks.qty');

        if ($mode === 'variant') {
            $query
                ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
                ->selectRaw('stocks.product_variant_id, SUM(stocks.qty) as qty, COUNT(*) as location_count')
                ->groupBy('stocks.product_variant_id', 'product_variants.sku')
                ->with('variant.product.unit')
                ->orderBy('product_variants.sku');

            if ($export) {
                return XlsxExport::download('stok-per-varian-'.now()->format('Ymd-His').'.xlsx',
                    ['SKU', 'Produk', 'Varian', 'Satuan', 'Total Qty', 'Jumlah Lokasi'],
                    $query->get()->map(fn ($s) => [
                        $s->variant?->sku, $s->variant?->product?->name, $s->variant?->name, $s->variant?->product?->unit?->code, (int) $s->qty, (int) $s->location_count,
                    ]));
            }
        } else {
            $query
                ->with(['variant.product.unit', 'warehouse', 'location'])
                ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'stocks.warehouse_location_id')
                ->orderBy('stocks.warehouse_id')
                ->orderBy('warehouse_locations.code')
                ->orderBy('stocks.id')
                ->select('stocks.*');

            if ($export) {
                return XlsxExport::download('stok-per-lokasi-'.now()->format('Ymd-His').'.xlsx',
                    ['Gudang', 'Lokasi', 'SKU', 'Barcode', 'Produk', 'Varian', 'Satuan', 'Qty'],
                    $query->lazy(500)->map(fn ($s) => [
                        $s->warehouse?->code, $s->location?->code ?? 'Tanpa bin', $s->variant?->sku, $s->variant?->barcode, $s->variant?->product?->name,
                        $s->variant?->name, $s->variant?->product?->unit?->code, (int) $s->qty,
                    ]));
            }
        }

        $stocks = $query->paginate(25)->withQueryString();

        // Per varian di satu gudang: tampilkan juga yang sedang dalam perjalanan ke gudang itu.
        $transit = $mode === 'variant' && $request->filled('warehouse')
            ? app(WarehouseSupply::class)->inTransit($request->integer('warehouse'), $stocks->pluck('product_variant_id')->all())
            : collect();

        return view('inventory.stocks.index', [
            'stocks' => $stocks,
            'mode' => $mode,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'totalQty' => $totalQty,
            'transit' => $transit,
        ]);
    }
}
