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
    private const PER_PAGE = 100;

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
        $carried = 0;
        $totalIn = 0;
        $totalOut = 0;
        $rows = null;

        if ($variant) {
            $scope = fn () => StockLedger::query()
                ->where('product_variant_id', $variant->id)
                ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
                ->when($request->input('location') === 'none', fn ($query) => $query->whereNull('warehouse_location_id'))
                ->when($request->filled('location') && $request->input('location') !== 'none', fn ($query) => $query->where('warehouse_location_id', $request->integer('location')));
            $inPeriod = fn () => $scope()
                ->whereDate('movement_date', '>=', $from)
                ->whereDate('movement_date', '<=', $to);

            // qty_in/qty_out unsigned: di MySQL selisih per baris yang minus ditolak, jadi jumlahkan dulu.
            $opening = (int) $scope()->whereDate('movement_date', '<', $from)->value(DB::raw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0)'));

            $totals = $inPeriod()->selectRaw('COALESCE(SUM(qty_in), 0) AS qty_in, COALESCE(SUM(qty_out), 0) AS qty_out')->first();
            $totalIn = (int) $totals->qty_in;
            $totalOut = (int) $totals->qty_out;

            $rows = $inPeriod()
                ->with(['movement', 'location', 'warehouse'])
                ->orderBy('movement_date')
                ->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            // Saldo berjalan di halaman 2 dst. dimulai dari saldo akhir halaman sebelumnya.
            $before = ($rows->currentPage() - 1) * $rows->perPage();
            $carried = $opening;

            if ($before > 0) {
                $earlier = DB::query()->fromSub(
                    $inPeriod()->orderBy('movement_date')->orderBy('id')->limit($before)->select(['qty_in', 'qty_out']),
                    'earlier',
                )->selectRaw('COALESCE(SUM(qty_in), 0) AS qty_in, COALESCE(SUM(qty_out), 0) AS qty_out')->first();
                $carried += (int) $earlier->qty_in - (int) $earlier->qty_out;
            }

            $balance = $carried;
            $rows->getCollection()->each(function (StockLedger $row) use (&$balance) {
                $balance += $row->qty_in - $row->qty_out;
                $row->setAttribute('balance', $balance);
            });
        }

        return view('inventory.stock-card.index', [
            'variant' => $variant,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'locations' => WarehouseLocation::query()
                ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
                ->orderBy('code')
                ->get(),
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'carried' => $carried,
            'rows' => $rows,
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
        ]);
    }
}
