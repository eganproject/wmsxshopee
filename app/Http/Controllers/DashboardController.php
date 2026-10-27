<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Support\StockLevels;
use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Ringkasan operasional gudang. Setiap panel hanya diisi kalau user punya
 * permission modulnya, dan mengikuti filter gudang (?warehouse=).
 */
class DashboardController extends Controller
{
    /** Jumlah hari pada grafik pergerakan barang. */
    private const CHART_DAYS = 14;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $warehouseId = $request->filled('warehouse') ? $request->integer('warehouse') : null;
        $today = today();

        // Statistik hanya untuk user dengan hak dashboard.view; tiap panel juga
        // butuh hak lihat modulnya. Tanpa hak itu halaman tetap terbuka (halaman
        // awal setelah login) tetapi hanya berisi keterangan tanpa angka.
        $can = fn (string $permission) => $user->can('dashboard.view') && $user->can($permission);

        $orders = null;
        if ($can('sales.view')) {
            $counts = SalesOrder::query()
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
            // Sama dengan tab di daftar pesanan: Selesai dipisah dari Dikirim.
            $counts['completed'] = SalesOrder::query()
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->inListTab('completed')->count();
            $counts['shipped'] = ($counts['shipped'] ?? 0) - $counts['completed'];

            $orders = [
                'counts' => collect(array_keys(SalesOrder::LIST_TABS))->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)]),
                'open' => collect(SalesOrder::RESERVING)->sum(fn ($status) => (int) ($counts[$status] ?? 0)),
                'shipped_today' => SalesOrder::query()
                    ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                    ->where('status', 'shipped')->where('shipped_at', '>=', $today)->count(),
            ];
        }

        $lowStock = null;
        if ($can('inventory.view')) {
            $query = StockLevels::belowMinimum(StockLevels::minStockQuery($warehouseId));
            $lowStock = [
                'count' => (clone $query)->reorder()->count(),
                'rows' => $query->limit(8)->get(),
            ];
        }

        $receipts = null;
        if ($can('receipts.view')) {
            $todayReceipts = GoodsReceipt::query()
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->whereDate('receipt_date', $today);

            $receipts = [
                'today' => (clone $todayReceipts)
                    ->with(['supplier:id,name', 'warehouse:id,code'])
                    ->withCount('items')
                    ->latest('id')->limit(6)->get(),
                'today_count' => $todayReceipts->count(),
                'drafts' => GoodsReceipt::query()
                    ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                    ->where('status', 'draft')->count(),
            ];
        }

        return view('dashboard', [
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'orders' => $orders,
            'lowStock' => $lowStock,
            'receipts' => $receipts,
            'chart' => $can('inventory.view') ? $this->movementChart($warehouseId, $today) : null,
            'activities' => $can('activity.view')
                ? ActivityLog::query()->latest('id')->limit(8)->get()
                : null,
        ]);
    }

    /**
     * Qty masuk & keluar per hari (penyesuaian dihitung sesuai arahnya).
     * Transfer antar lokasi tidak mengubah saldo gudang sehingga tidak dihitung.
     * Tanpa filter gudang, transfer antar gudang yang kedua gudangnya terlihat
     * juga dilewati (keluar di asal dan masuk di tujuan bukan barang masuk/keluar baru).
     *
     * @return array{days: Collection, max: int, in: int, out: int}
     */
    private function movementChart(?int $warehouseId, Carbon $today): array
    {
        $from = $today->copy()->subDays(self::CHART_DAYS - 1);

        $rows = StockLedger::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('type', '!=', 'transfer')
            ->when(! $warehouseId, fn ($q) => $this->skipInternalTransfers($q))
            ->where('movement_date', '>=', $from->toDateString())
            ->where('movement_date', '<', $today->copy()->addDay()->toDateString())
            ->toBase()
            ->groupByRaw('DATE(movement_date)')
            ->selectRaw('DATE(movement_date) as day, SUM(qty_in) as qty_in, SUM(qty_out) as qty_out')
            ->get()
            ->keyBy('day');

        $days = collect(range(0, self::CHART_DAYS - 1))->map(function ($offset) use ($from, $rows) {
            $date = $from->copy()->addDays($offset);
            $row = $rows[$date->toDateString()] ?? null;

            return ['date' => $date, 'in' => (int) ($row->qty_in ?? 0), 'out' => (int) ($row->qty_out ?? 0)];
        });

        return [
            'days' => $days,
            'max' => max(1, $days->max('in'), $days->max('out')),
            'in' => $days->sum('in'),
            'out' => $days->sum('out'),
        ];
    }

    /**
     * Lewati baris transfer_out/transfer_in dari transfer antar gudang yang
     * gudang asal dan tujuannya sama-sama bisa diakses user.
     */
    private function skipInternalTransfers(Builder $query): void
    {
        $ids = WarehouseAccess::ids();

        $query->whereNot(fn ($q) => $q->whereIn('stock_ledgers.type', ['transfer_out', 'transfer_in'])
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('stock_movements')
                ->join('warehouse_transfers', 'warehouse_transfers.number', '=', 'stock_movements.reference')
                ->whereColumn('stock_movements.id', 'stock_ledgers.stock_movement_id')
                ->when($ids !== null, fn ($w) => $w->whereIn('warehouse_transfers.from_warehouse_id', $ids)
                    ->whereIn('warehouse_transfers.to_warehouse_id', $ids))));
    }
}
