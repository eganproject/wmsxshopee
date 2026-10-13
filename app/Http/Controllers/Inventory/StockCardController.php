<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockCardController extends Controller
{
    /** Kartu stok satu varian: saldo awal, setiap mutasi dan saldo berjalan. */
    public function index(Request $request): View
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $variant = null;

        if ($request->filled('variant')) {
            $variant = ProductVariant::with('product.unit')->find($request->integer('variant'));
        } elseif ($request->filled('code')) {
            // Bisa langsung scan SKU varian atau barcode.
            $code = strtoupper(trim($request->string('code')->toString()));
            $variant = ProductVariant::with('product.unit')->where('sku', $code)->orWhere('barcode', $code)->first();
        }

        $opening = 0;
        $rows = collect();

        if ($variant) {
            $scope = fn () => StockLedger::query()
                ->where('product_variant_id', $variant->id)
                ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
                ->when($request->filled('location'), fn ($query) => $query->where('warehouse_location_id', $request->integer('location')));

            $opening = (int) $scope()->whereDate('movement_date', '<', $from)->sum(DB::raw('qty_in - qty_out'));

            $balance = $opening;
            $rows = $scope()
                ->with(['movement', 'location', 'warehouse'])
                ->whereDate('movement_date', '>=', $from)
                ->whereDate('movement_date', '<=', $to)
                ->orderBy('movement_date')
                ->orderBy('id')
                ->get()
                ->each(function (StockLedger $row) use (&$balance) {
                    $balance += $row->qty_in - $row->qty_out;
                    $row->setAttribute('balance', $balance);
                });
        }

        return view('inventory.stock-card.index', [
            'variant' => $variant,
            'variants' => ProductVariant::with('product:id,name')->orderBy('sku')->get(),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'locations' => WarehouseLocation::query()
                ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
                ->orderBy('code')
                ->get(),
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'rows' => $rows,
            'totalIn' => $rows->sum('qty_in'),
            'totalOut' => $rows->sum('qty_out'),
        ]);
    }
}
