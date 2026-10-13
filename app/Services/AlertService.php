<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\StockLevels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Peringatan operasional yang dihitung langsung dari data (tanpa tabel
 * notifikasi): stok menipis, paket yang tidak bisa dipenuhi, pesanan
 * tertunda dan penerimaan draft yang terlalu lama. Setiap grup hanya muncul
 * kalau user punya permission modulnya; datanya otomatis dibatasi ke gudang
 * yang boleh diakses user.
 */
class AlertService
{
    public function __construct(private SalesOrderService $orders) {}

    /**
     * Ringkasan untuk lonceng di topbar: jumlah per grup, disimpan sebentar
     * di cache per user supaya tidak dihitung ulang di setiap halaman.
     *
     * @return array{total: int, groups: list<array{key: string, label: string, hint: string, count: int, icon: string, tone: string}>}
     */
    public function summary(User $user): array
    {
        return Cache::remember("alerts.summary.{$user->id}", (int) config('alerts.cache_seconds', 60), function () use ($user) {
            $groups = collect($this->groups($user, 0))
                ->map(fn ($group) => collect($group)->except('items')->all())
                ->values();

            return ['total' => $groups->sum('count'), 'groups' => $groups->all()];
        });
    }

    public static function forget(User $user): void
    {
        Cache::forget("alerts.summary.{$user->id}");
    }

    /**
     * Grup peringatan lengkap dengan contoh baris (maks $limit per grup).
     *
     * @return array<string, array{key: string, label: string, hint: string, count: int, icon: string, tone: string, items: Collection}>
     */
    public function groups(User $user, int $limit = 10): array
    {
        $groups = [];

        if ($user->can('inventory.view')) {
            $query = StockLevels::belowMinimum(StockLevels::minStockQuery());
            $count = (clone $query)->reorder()->count();
            $empty = StockLevels::belowMinimum(StockLevels::minStockQuery())->reorder()
                ->whereRaw(StockLevels::AVAILABLE.' <= 0')->count();

            $groups['low_stock'] = [
                'key' => 'low_stock',
                'label' => 'Stok menipis',
                'hint' => $empty ? "{$empty} SKU sudah habis" : 'Stok tersedia di bawah atau sama dengan stok minimum',
                'count' => $count,
                'icon' => 'heroicon-o-bell-alert',
                'tone' => $empty ? 'rose' : 'amber',
                'items' => $limit && $count ? $query->limit($limit)->get() : collect(),
            ];

            $bundles = $this->unavailableBundles();
            $groups['bundles'] = [
                'key' => 'bundles',
                'label' => 'Paket tidak bisa dipenuhi',
                'hint' => 'Stok komponen tidak cukup untuk satu paket pun',
                'count' => $bundles->count(),
                'icon' => 'heroicon-o-gift',
                'tone' => 'rose',
                'items' => $bundles->take($limit)->values(),
            ];
        }

        if ($user->can('sales.view')) {
            $hours = (int) config('alerts.pending_order_hours', 24);
            $pending = SalesOrder::query()
                ->whereIn('status', SalesOrder::RESERVING)
                ->where('confirmed_at', '<=', now()->subHours($hours));

            $groups['pending_orders'] = [
                'key' => 'pending_orders',
                'label' => 'Pesanan tertunda',
                'hint' => "Dikonfirmasi lebih dari {$hours} jam lalu tapi belum dikirim",
                'count' => (clone $pending)->count(),
                'icon' => 'heroicon-o-clock',
                'tone' => 'amber',
                'items' => $limit ? $pending->with('warehouse:id,code')->oldest('confirmed_at')->limit($limit)->get() : collect(),
            ];

            $draftHours = (int) config('alerts.draft_order_hours', 24);
            $drafts = SalesOrder::query()
                ->where('status', 'draft')
                ->where('created_at', '<=', now()->subHours($draftHours));

            $groups['draft_orders'] = [
                'key' => 'draft_orders',
                'label' => 'Pesanan draft belum dikonfirmasi',
                'hint' => "Dibuat lebih dari {$draftHours} jam lalu",
                'count' => (clone $drafts)->count(),
                'icon' => 'heroicon-o-document',
                'tone' => 'slate',
                'items' => $limit ? $drafts->with('warehouse:id,code')->oldest('created_at')->limit($limit)->get() : collect(),
            ];
        }

        if ($user->can('receipts.view')) {
            $days = (int) config('alerts.draft_receipt_days', 2);
            $receipts = GoodsReceipt::query()
                ->where('status', 'draft')
                ->where('created_at', '<=', now()->subDays($days));

            $groups['draft_receipts'] = [
                'key' => 'draft_receipts',
                'label' => 'Penerimaan belum dikonfirmasi',
                'hint' => "Draft lebih dari {$days} hari, stok belum masuk",
                'count' => (clone $receipts)->count(),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'tone' => 'slate',
                'items' => $limit ? $receipts->with(['supplier:id,name', 'warehouse:id,code'])->oldest('created_at')->limit($limit)->get() : collect(),
            ];
        }

        return $groups;
    }

    /**
     * Paket aktif yang stok virtualnya 0 di semua gudang yang boleh diakses:
     * per gudang dihitung dari komponen paling sedikit, lalu dijumlahkan.
     *
     * @return Collection<int, Bundle>
     */
    public function unavailableBundles(): Collection
    {
        $bundles = Bundle::query()->where('is_active', true)->has('items')->with('items.variant')->orderBy('sku')->get();
        if ($bundles->isEmpty()) {
            return collect();
        }

        $totals = $bundles->mapWithKeys(fn ($bundle) => [$bundle->id => 0]);
        foreach (Warehouse::query()->where('is_active', true)->pluck('id') as $warehouseId) {
            foreach ($this->orders->bundleAvailability($warehouseId, $bundles) as $bundleId => $qty) {
                $totals[$bundleId] += $qty;
            }
        }

        return $bundles->filter(fn ($bundle) => $totals[$bundle->id] === 0)->values();
    }
}
