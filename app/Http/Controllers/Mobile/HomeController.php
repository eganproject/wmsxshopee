<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockCount;
use App\Support\MobileApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Beranda, akun & data sesi aplikasi Android. */
class HomeController extends Controller
{
    use MobileContext;

    public function index(Request $request): View
    {
        $user = $request->user();
        $warehouse = $this->mobileWarehouse($request);
        $id = $warehouse?->id;

        $count = fn ($query) => $id ? $query->where('warehouse_id', $id)->count() : 0;

        $stats = [
            'picking' => $count(SalesOrder::where('status', 'picking')),
            'packed' => $count(SalesOrder::where('status', 'packed')->where('delivery_type', 'courier')),
            'packed_today' => $count(SalesOrder::where('packed_at', '>=', today())),
            'shipped_today' => $count(SalesOrder::where('shipped_at', '>=', today())),
            'couriers_waiting' => $id ? SalesOrder::where('warehouse_id', $id)->where('status', 'packed')->where('delivery_type', 'courier')->distinct()->count('courier_id') : 0,
            'receipts_draft' => $count(GoodsReceipt::where('status', 'draft')),
            'returns_draft' => $count(SalesReturn::where('status', 'draft')),
            'counts_draft' => $count(StockCount::where('status', 'draft')),
        ];

        // Target = semua paket yang dikemas hari ini + yang masih menunggu QC.
        $target = $stats['packed_today'] + $stats['picking'];

        return view('mobile.home', [
            'user' => $user,
            'warehouse' => $warehouse,
            'warehouses' => $this->mobileWarehouses(),
            'stats' => $stats,
            'target' => $target,
            'progress' => $target > 0 ? (int) round($stats['packed_today'] / $target * 100) : 0,
            'features' => $this->features($request),
        ]);
    }

    public function warehouse(Request $request): RedirectResponse
    {
        $ids = $this->mobileWarehouses()->pluck('id')->all();
        $data = $request->validate(['warehouse_id' => ['required', 'integer', Rule::in($ids)]], [], ['warehouse_id' => 'gudang']);
        $request->session()->put('mobile_warehouse_id', (int) $data['warehouse_id']);

        return redirect()->route('mobile.home');
    }

    public function account(Request $request): View
    {
        return view('mobile.account', [
            'user' => $request->user()->load('roles'),
            'warehouse' => $this->mobileWarehouse($request),
            'app' => MobileApp::version(),
        ]);
    }

    public function outbox(): View
    {
        return view('mobile.outbox');
    }

    /** Halaman cadangan saat offline dan halaman belum pernah dibuka. */
    public function offline(): View
    {
        return view('mobile.offline');
    }

    /** Token CSRF terbaru untuk mengirim ulang antrean offline, plus versi APK terbaru. */
    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'csrf' => csrf_token(),
            'user' => $request->user()->only(['id', 'name', 'username']),
            'warehouse_id' => $this->mobileWarehouse($request)?->id,
            'app' => MobileApp::version(),
        ]);
    }

    /**
     * Menu aplikasi, tampil sesuai hak akses user.
     *
     * @return list<array<string, mixed>>
     */
    private function features(Request $request): array
    {
        $user = $request->user();

        return array_values(array_filter([
            $user->can('sales.fulfill') ? ['route' => 'mobile.qc', 'label' => 'QC Kemas', 'icon' => 'pkgcheck', 'tone' => 'vio', 'stat' => 'picking', 'hint' => ':n antre'] : null,
            $user->can('sales.fulfill') ? ['route' => 'mobile.handover', 'label' => 'Serah Terima', 'icon' => 'truck', 'tone' => 'info', 'stat' => 'packed', 'hint' => ':n paket siap'] : null,
            $user->can('receipts.create') ? ['route' => 'mobile.receipts', 'label' => 'Penerimaan', 'icon' => 'inbox', 'tone' => 'ok', 'stat' => 'receipts_draft', 'hint' => ':n draft'] : null,
            $user->can('opname.create') ? ['route' => 'mobile.opname', 'label' => 'Stock Opname', 'icon' => 'clip', 'tone' => 'warn', 'stat' => 'counts_draft', 'hint' => ':n lembar aktif'] : null,
            $user->can('adjustments.create') ? ['route' => 'mobile.stock-check', 'label' => 'Cek Stok Rak', 'icon' => 'layers', 'tone' => 'amber', 'stat' => null, 'hint' => 'Hitung ulang 1 rak'] : null,
            $user->can('returns.create') ? ['route' => 'mobile.returns', 'label' => 'Retur', 'icon' => 'undo', 'tone' => 'bad', 'stat' => 'returns_draft', 'hint' => ':n draft'] : null,
            $user->canAny(['products.view', 'warehouses.view', 'sales.fulfill']) ? ['route' => 'mobile.print', 'label' => 'Cetak Label', 'icon' => 'printer', 'tone' => 'brand', 'stat' => null, 'hint' => 'Printer Bluetooth'] : null,
        ]));
    }
}
