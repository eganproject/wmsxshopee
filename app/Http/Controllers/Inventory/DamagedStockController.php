<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\DamagedDocument;
use App\Models\DamagedStock;
use App\Models\DamagedStockLedger;
use App\Models\Warehouse;
use App\Support\XlsxExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Saldo stok rusak per gudang dan kartu stok rusaknya. */
class DamagedStockController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = DamagedStock::query()
            ->with(['variant.product.unit', 'warehouse'])
            ->join('product_variants', 'product_variants.id', '=', 'damaged_stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select('damaged_stocks.*')
            ->when(! $request->boolean('zero'), fn ($query) => $query->where('damaged_stocks.qty', '>', 0))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('damaged_stocks.warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $like = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)
                    ->orWhere('product_variants.barcode', 'like', $like)
                    ->orWhere('products.name', 'like', $like));
            })
            ->orderBy('product_variants.sku')
            ->orderBy('damaged_stocks.warehouse_id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('stok-rusak-'.now()->format('Ymd-His').'.xlsx',
                ['SKU', 'Produk', 'Varian', 'Gudang', 'Qty Rusak', 'Satuan'],
                $query->lazy(500)->map(fn ($row) => [
                    $row->variant->sku, $row->variant->product->name, $row->variant->name ?? '', $row->warehouse->code, $row->qty, $row->variant->product->unit?->code,
                ]));
        }

        $warehouseFilter = fn ($query) => $query->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse')));
        $month = DamagedStockLedger::query()->tap($warehouseFilter)->where('movement_date', '>=', now()->startOfMonth()->toDateString());

        return view('inventory.damaged.index', [
            'stocks' => $query->paginate(30)->withQueryString(),
            'summary' => [
                'qty' => (int) DamagedStock::query()->tap($warehouseFilter)->sum('qty'),
                'skus' => DamagedStock::query()->tap($warehouseFilter)->where('qty', '>', 0)->distinct()->count('product_variant_id'),
                'in' => (int) (clone $month)->sum('qty_in'),
                'out' => (int) (clone $month)->sum('qty_out'),
            ],
            'drafts' => DamagedDocument::query()->tap($warehouseFilter)->where('status', 'draft')->count(),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'actions' => $this->allowedTypes($request),
        ]);
    }

    public function ledger(Request $request): View|BinaryFileResponse
    {
        $query = DamagedStockLedger::query()
            ->with(['variant.product', 'warehouse', 'creator'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('variant'), fn ($query) => $query->where('product_variant_id', $request->integer('variant')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('movement_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('movement_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $like = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('reference', 'like', $like)
                    ->orWhereHas('variant', fn ($v) => $v->where('sku', 'like', $like)->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like))));
            })
            ->latest('movement_date')
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('kartu-stok-rusak-'.now()->format('Ymd-His').'.xlsx',
                ['Tanggal', 'Jenis', 'Referensi', 'SKU', 'Produk', 'Gudang', 'Masuk', 'Keluar', 'Keterangan', 'Oleh'],
                $query->lazy(500)->map(fn ($row) => [
                    $row->movement_date->format('d/m/Y'), $row->typeLabel(), $row->reference, $row->variant->sku, $row->variant->product->name,
                    $row->warehouse->code, $row->qty_in, $row->qty_out, $row->notes, $row->creator?->name,
                ]));
        }

        return view('inventory.damaged.ledger', [
            'ledgers' => $query->paginate(30)->withQueryString(),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'types' => DamagedStockLedger::types(),
        ]);
    }

    /**
     * Jenis dokumen yang boleh dibuat user ini.
     *
     * @return array<string, array<string, string>>
     */
    public static function allowedTypes(Request $request): array
    {
        return collect(DamagedDocument::TYPES)->filter(fn ($meta) => $request->user()->can($meta['permission']))->all();
    }
}
