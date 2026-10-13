<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\WarehouseZone;
use App\Support\StockLevels;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Laporan stok; setiap laporan bisa diekspor ke Excel lewat ?export=xlsx. */
class ReportController extends Controller
{
    /** Stok per lokasi, termasuk stok "Tanpa bin" di level gudang. */
    public function stockLocations(Request $request): View|BinaryFileResponse
    {
        $zone = $request->input('zone');

        $query = Stock::query()
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'stocks.warehouse_location_id')
            ->leftJoin('warehouse_racks', 'warehouse_racks.id', '=', 'warehouse_locations.warehouse_rack_id')
            ->leftJoin('warehouse_zones', 'warehouse_zones.id', '=', 'warehouse_racks.warehouse_zone_id')
            ->when(! $request->boolean('zero'), fn ($q) => $q->where('stocks.qty', '!=', 0))
            ->when($request->filled('warehouse'), fn ($q) => $q->where('stocks.warehouse_id', $request->integer('warehouse')))
            ->when($zone === 'none', fn ($q) => $q->whereNull('stocks.warehouse_location_id'))
            ->when(filled($zone) && $zone !== 'none', fn ($q) => $q->where('warehouse_zones.id', (int) $zone))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $like = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)
                    ->orWhere('product_variants.barcode', 'like', $like)
                    ->orWhere('products.name', 'like', $like)
                    ->orWhere('warehouse_locations.code', 'like', $like));
            })
            ->orderBy('warehouses.code')
            ->orderByRaw('stocks.warehouse_location_id IS NULL')
            ->orderBy('warehouse_zones.code')
            ->orderBy('warehouse_locations.code')
            ->orderBy('product_variants.sku')
            ->select([
                'stocks.product_variant_id', 'stocks.warehouse_id', 'stocks.warehouse_location_id', 'stocks.qty',
                'warehouses.code as warehouse_code', 'warehouse_zones.code as zone_code', 'warehouse_racks.code as rack_code',
                'warehouse_locations.code as location_code', 'product_variants.sku', 'product_variants.name as variant_name',
                'products.name as product_name', 'units.code as unit_code',
            ]);

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('stok-per-lokasi-'.now()->format('Ymd-His').'.xlsx',
                ['Gudang', 'Zona', 'Rak', 'Lokasi', 'SKU', 'Produk', 'Varian', 'Satuan', 'Stok'],
                $query->cursor()->map(fn ($row) => [
                    $row->warehouse_code, $row->zone_code ?? '', $row->rack_code ?? '', $row->location_code ?? 'Tanpa bin',
                    $row->sku, $row->product_name, $row->variant_name ?? '', $row->unit_code ?? '', (int) $row->qty,
                ]));
        }

        $summary = (clone $query)->reorder()->toBase()->select(DB::raw(
            'COALESCE(SUM(stocks.qty), 0) as qty, COUNT(DISTINCT stocks.product_variant_id) as skus, '.
            'COUNT(DISTINCT stocks.warehouse_location_id) as bins, '.
            'COALESCE(SUM(CASE WHEN stocks.warehouse_location_id IS NULL THEN stocks.qty ELSE 0 END), 0) as unbinned'
        ))->first();

        return view('reports.stock-locations', [
            'rows' => $query->paginate(50)->withQueryString(),
            'summary' => $summary,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'zones' => WarehouseZone::with('warehouse:id,code')
                ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse')))
                ->orderBy('warehouse_id')->orderBy('code')->get(),
        ]);
    }

    /**
     * Varian yang stok tersedianya (fisik dikurangi cadangan pesanan) sudah
     * mencapai atau di bawah stok minimum.
     */
    public function minStock(Request $request): View|BinaryFileResponse
    {
        $warehouseId = $request->filled('warehouse') ? $request->integer('warehouse') : null;
        $all = $request->input('show') === 'all';

        $query = StockLevels::minStockQuery($warehouseId)
            ->when(! $all, fn ($q) => StockLevels::belowMinimum($q))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $like = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)->orWhere('products.name', 'like', $like));
            });

        $belowCount = StockLevels::belowMinimum((clone $query)->reorder())->count();

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('stok-minimum-'.now()->format('Ymd-His').'.xlsx',
                ['SKU', 'Produk', 'Varian', 'Satuan', 'Stok Minimum', 'Stok Fisik', 'Dicadangkan', 'Tersedia', 'Kekurangan'],
                $query->cursor()->map(fn ($row) => [
                    $row->sku, $row->product_name, $row->variant_name ?? '', $row->unit_code ?? '',
                    (int) $row->min_stock, (int) $row->on_hand, (int) $row->reserved, (int) $row->available,
                    max((int) $row->min_stock - (int) $row->available, 0),
                ]));
        }

        return view('reports.min-stock', [
            'rows' => $query->paginate(50)->withQueryString(),
            'belowCount' => $belowCount,
            'withMinCount' => ProductVariant::whereNotNull('min_stock')->where('is_active', true)->count(),
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    /**
     * Pergerakan barang per varian dalam rentang tanggal: saldo awal, masuk,
     * keluar, penyesuaian dan saldo akhir. Transfer antar lokasi di satu gudang
     * tidak mengubah saldo gudang sehingga tidak ditampilkan terpisah.
     */
    public function movements(Request $request): View|BinaryFileResponse
    {
        $from = ($request->date('from') ?? now()->startOfMonth())->startOfDay();
        $to = ($request->date('to') ?? now())->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // Rentang setengah terbuka [from, to+1) supaya benar untuk kolom date maupun datetime.
        $start = $from->toDateString();
        $end = $to->copy()->addDay()->toDateString();
        $inRange = "stock_ledgers.movement_date >= '{$start}'";

        $query = StockLedger::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_ledgers.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('stock_ledgers.movement_date', '<', $end)
            ->when($request->filled('warehouse'), fn ($q) => $q->where('stock_ledgers.warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $like = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)->orWhere('products.name', 'like', $like));
            })
            ->groupBy('stock_ledgers.product_variant_id', 'product_variants.sku', 'product_variants.name', 'products.name', 'units.code')
            ->when(! $request->boolean('all'), fn ($q) => $q->havingRaw("SUM(CASE WHEN {$inRange} THEN 1 ELSE 0 END) > 0"))
            ->orderBy('product_variants.sku')
            ->selectRaw("stock_ledgers.product_variant_id, product_variants.sku, product_variants.name as variant_name,
                products.name as product_name, units.code as unit_code,
                SUM(CASE WHEN {$inRange} THEN 0 ELSE stock_ledgers.qty_in - stock_ledgers.qty_out END) as opening,
                SUM(CASE WHEN {$inRange} AND stock_ledgers.type = 'in' THEN stock_ledgers.qty_in ELSE 0 END) as qty_in,
                SUM(CASE WHEN {$inRange} AND stock_ledgers.type = 'out' THEN stock_ledgers.qty_out ELSE 0 END) as qty_out,
                SUM(CASE WHEN {$inRange} AND stock_ledgers.type = 'adjustment' THEN stock_ledgers.qty_in - stock_ledgers.qty_out ELSE 0 END) as adjusted,
                SUM(stock_ledgers.qty_in - stock_ledgers.qty_out) as closing");

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download("pergerakan-barang-{$from->format('Ymd')}-{$to->format('Ymd')}.xlsx",
                ['SKU', 'Produk', 'Varian', 'Satuan', 'Saldo Awal', 'Masuk', 'Keluar', 'Penyesuaian', 'Saldo Akhir'],
                $query->cursor()->map(fn ($row) => [
                    $row->sku, $row->product_name, $row->variant_name ?? '', $row->unit_code ?? '',
                    (int) $row->opening, (int) $row->qty_in, (int) $row->qty_out, (int) $row->adjusted, (int) $row->closing,
                ]));
        }

        $totals = DB::query()->fromSub((clone $query)->reorder(), 'rows')
            ->selectRaw('COALESCE(SUM(opening), 0) as opening, COALESCE(SUM(qty_in), 0) as qty_in, COALESCE(SUM(qty_out), 0) as qty_out,
                COALESCE(SUM(adjusted), 0) as adjusted, COALESCE(SUM(closing), 0) as closing')
            ->first();

        return view('reports.movements', [
            'rows' => $query->paginate(50)->withQueryString(),
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }
}
