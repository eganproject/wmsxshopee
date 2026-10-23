<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Support\StockLevels;
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

            $orders = [
                'counts' => collect(array_keys(SalesOrder::STATUSES))->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)]),
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
     *
     * @return array{days: Collection, max: int, in: int, out: int}
     */
    private function movementChart(?int $warehouseId, Carbon $today): array
    {
        $from = $today->copy()->subDays(self::CHART_DAYS - 1);

        $rows = StockLedger::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('type', '!=', 'transfer')
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
}
