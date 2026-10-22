<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\GoodsReceipt;
use App\Models\MarketplaceWebhookEvent;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\StockLevels;
use Illuminate\Support\Carbon;
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

            // Gudang kecil yang perlu diisi ulang dari gudang besar (cadangan).
            $supply = app(WarehouseSupply::class);
            $refill = Warehouse::query()->whereNotNull('supply_warehouse_id')->where('is_active', true)->orderBy('code')->get()
                ->flatMap(fn (Warehouse $warehouse) => $supply->suggestions($warehouse)->each(fn ($row) => $row->warehouse = $warehouse));
            $urgent = $refill->where('urgent', true)->count();

            $groups['refill'] = [
                'key' => 'refill',
                'label' => 'Gudang kecil perlu diisi ulang',
                'hint' => $urgent ? "{$urgent} SKU kurang untuk pesanan yang sudah masuk" : 'Stok di bawah minimum, gudang cadangan masih ada',
                'count' => $refill->count(),
                'icon' => 'heroicon-o-truck',
                'tone' => $urgent ? 'rose' : 'amber',
                'items' => $refill->take($limit ?: 0)->values(),
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

            $courierHours = (int) config('alerts.courier_scan_hours', 24);
            $courier = SalesOrder::query()->shopeeFilter('courier_overdue');

            $groups['courier_pending'] = [
                'key' => 'courier_pending',
                'label' => 'Paket belum discan kurir',
                'hint' => "Sudah serah terima lebih dari {$courierHours} jam, tapi di Shopee masih Diproses",
                'count' => (clone $courier)->count(),
                'icon' => 'heroicon-o-truck',
                'tone' => 'rose',
                'items' => $limit ? $courier->with('warehouse:id,code')->oldest('shipped_at')->limit($limit)->get() : collect(),
            ];

            $ahead = SalesOrder::query()->shopeeFilter('shopee_ahead');

            $groups['shopee_ahead'] = [
                'key' => 'shopee_ahead',
                'label' => 'Dikirim di Shopee, belum di gudang',
                'hint' => 'Pengiriman otomatis gagal; buka pesanan untuk melihat alasannya',
                'count' => (clone $ahead)->count(),
                'icon' => 'heroicon-o-exclamation-triangle',
                'tone' => 'amber',
                'items' => $limit ? $ahead->with('warehouse:id,code')->oldest('shopee_synced_at')->limit($limit)->get() : collect(),
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

        if ($user->can('marketplace.view')) {
            $issues = $this->marketplaceIssues();

            $groups['marketplace'] = [
                'key' => 'marketplace',
                'label' => 'Koneksi marketplace',
                'hint' => 'Toko perlu otorisasi ulang, sinkron terhenti, stok gagal terkirim atau push gagal diproses',
                'count' => $issues->count(),
                'icon' => 'heroicon-o-signal-slash',
                'tone' => $issues->contains('severe', true) ? 'rose' : 'amber',
                'items' => $issues->take($limit ?: 0)->values(),
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
     * Masalah koneksi per toko Shopee & TikTok yang aktif.
     *
     * @return Collection<int, object{platform: string, shop: string, problem: string, since: ?Carbon, severe: bool, url: string}>
     */
    public function marketplaceIssues(): Collection
    {
        $stale = now()->subMinutes((int) config('alerts.marketplace.stale_sync_minutes', 30));
        $authLimit = now()->addDays((int) config('alerts.marketplace.authorization_days', 14));
        $issues = collect();
        $add = fn (string $platform, $shop, string $problem, $since, bool $severe, ?string $url = null) => $issues->push((object) [
            'platform' => $platform,
            'shop' => ($platform === 'shopee' ? 'Shopee' : 'TikTok').' · '.$shop->displayName(),
            'problem' => $problem,
            'since' => $since,
            'severe' => $severe,
            'url' => $url ?? route('marketplace.sync-logs', ['shop' => "{$platform}-{$shop->id}"]),
        ]);

        foreach (['shopee' => ShopeeShop::class, 'tiktok' => TiktokShop::class] as $platform => $model) {
            $shops = $model::where('is_active', true)->whereIn('status', ['connected', 'expired'])
                ->withCount(['mappings as failed_stock' => fn ($q) => $q->whereNotNull('stock_error')])
                ->get();

            foreach ($shops as $shop) {
                if ($shop->status === 'expired') {
                    $add($platform, $shop, 'Perlu otorisasi ulang'.($shop->last_error ? ': '.$shop->last_error : ''), $shop->updated_at, true, route('marketplace.shops', ['platform' => $platform]));

                    continue;
                }

                $authEnds = $platform === 'shopee' ? $shop->authorization_expires_at : $shop->refresh_token_expires_at;
                if ($authEnds && $authEnds->lt($authLimit)) {
                    $add($platform, $shop, 'Otorisasi berakhir '.$authEnds->translatedFormat('d M Y').'. Hubungkan ulang toko sebelum tanggal itu.', $authEnds, $authEnds->lt(now()->addDays(3)), route('marketplace.shops', ['platform' => $platform]));
                }

                if ($shop->isSyncable() && ($shop->last_synced_at ?? $shop->connected_at)?->lt($stale)) {
                    $add($platform, $shop, 'Sinkron pesanan terhenti'.($shop->last_error ? ': '.$shop->last_error : ''), $shop->last_synced_at, true);
                }

                if ($shop->failed_stock > 0) {
                    $add($platform, $shop, "{$shop->failed_stock} SKU gagal dikirim stoknya", null, false, route('marketplace.products', ['status' => 'error', 'shop' => "{$platform}-{$shop->id}"]));
                }
            }
        }

        $pushes = MarketplaceWebhookEvent::where('status', 'failed')->where('created_at', '>=', now()->subDay())
            ->selectRaw('platform, COUNT(*) as total, MAX(updated_at) as last_at')->groupBy('platform')->get();

        foreach ($pushes as $row) {
            $issues->push((object) [
                'platform' => $row->platform,
                'shop' => $row->platform === 'shopee' ? 'Push Shopee' : 'Webhook TikTok',
                'problem' => "{$row->total} push gagal diproses dalam 24 jam terakhir (sinkron terjadwal tetap menutupnya)",
                'since' => $row->last_at ? Carbon::parse($row->last_at) : null,
                'severe' => false,
                'url' => route('marketplace.sync-logs', ['tab' => 'push', 'status' => 'failed']),
            ]);
        }

        return $issues->sortByDesc('severe')->values();
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
