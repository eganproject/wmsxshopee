<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    /** Stok saat ini, per lokasi atau dijumlahkan per varian. */
    public function index(Request $request): View
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

        $totalQty = (int) (clone $query)->sum('stocks.qty');

        if ($mode === 'variant') {
            $stocks = $query
                ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
                ->selectRaw('stocks.product_variant_id, SUM(stocks.qty) as qty, COUNT(*) as location_count')
                ->groupBy('stocks.product_variant_id', 'product_variants.sku')
                ->with('variant.product.unit')
                ->orderBy('product_variants.sku')
                ->paginate(25)
                ->withQueryString();
        } else {
            $stocks = $query
                ->with(['variant.product.unit', 'warehouse', 'location'])
                ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'stocks.warehouse_location_id')
                ->orderBy('stocks.warehouse_id')
                ->orderBy('warehouse_locations.code')
                ->select('stocks.*')
                ->paginate(25)
                ->withQueryString();
        }

        return view('inventory.stocks.index', [
            'stocks' => $stocks,
            'mode' => $mode,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'totalQty' => $totalQty,
        ]);
    }
}
