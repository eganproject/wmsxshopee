<?php

namespace App\Services;

use App\Models\AlertRead;
use App\Models\Bundle;
use App\Models\GoodsReceipt;
use App\Models\MarketplaceWebhookEvent;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\StockAdjustment;
use App\Models\TiktokShop;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\FailedJobs;
use App\Support\StockLevels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
     * @return array{total: int, unread: int, groups: list<array{key: string, label: string, hint: string, count: int, unread: int, icon: string, tone: string}>}
     */
    public function summary(User $user): array
    {
        return Cache::remember("alerts.summary.{$user->id}", (int) config('alerts.cache_seconds', 60), function () use ($user) {
            $groups = collect($this->groups($user, 0))
                ->map(fn ($group) => collect($group)->except(['items', 'keys'])->all())
                ->values();

            return ['total' => $groups->sum('count'), 'unread' => $groups->sum('unread'), 'groups' => $groups->all()];
        });
    }

    public static function forget(User $user): void
    {
        Cache::forget("alerts.summary.{$user->id}");
    }

    /**
     * Grup peringatan lengkap dengan contoh baris (maks $limit per grup).
     *
     * Setiap grup juga membawa `keys` (kunci semua baris) dan `unread` (jumlah
     * baris yang belum ditandai dibaca user). Baris yang belum dibaca tampil
     * lebih dulu dan diberi `alert_unread = true`.
     *
     * @return array<string, array{key: string, label: string, hint: string, count: int, unread: int, icon: string, tone: string, keys: list<string>, items: Collection}>
     */
    public function groups(User $user, int $limit = 10): array
    {
        $read = $this->readKeys($user);
        $groups = [];

        if ($user->can('inventory.view')) {
            // Satu baris per SKU per gudang; kunci baris "gudang-varian" seperti grup isi ulang.
            $low = StockLevels::belowMinimum(StockLevels::minStockQuery())->toBase()->get()
                ->each(fn ($row) => $row->alert_key = "{$row->warehouse_id}-{$row->id}");
            $empty = $low->filter(fn ($row) => (int) $row->available <= 0)->count();

            $groups['low_stock'] = [
                'key' => 'low_stock',
                'label' => 'Stok menipis',
                'hint' => $empty ? "{$empty} SKU sudah habis di gudangnya" : 'Stok tersedia di bawah atau sama dengan stok minimum gudang',
                'keys' => $low->pluck('alert_key')->all(),
                'icon' => 'heroicon-o-bell-alert',
                'tone' => $empty ? 'rose' : 'amber',
                'items' => $low->values(),
            ];

            // Gudang kecil yang perlu diisi ulang dari gudang besar (cadangan).
            $supply = app(WarehouseSupply::class);
            $refill = Warehouse::query()->whereNotNull('supply_warehouse_id')->where('is_active', true)->orderBy('code')->get()
                ->flatMap(fn (Warehouse $warehouse) => $supply->suggestions($warehouse)->each(function ($row) use ($warehouse) {
                    $row->warehouse = $warehouse;
                    $row->alert_key = "{$warehouse->id}-{$row->variant->id}";
                }));
            $urgent = $refill->where('urgent', true)->count();

            $groups['refill'] = [
                'key' => 'refill',
                'label' => 'Gudang kecil perlu diisi ulang',
                'hint' => $urgent ? "{$urgent} SKU kurang untuk pesanan yang sudah masuk" : 'Stok di bawah minimum, gudang cadangan masih ada',
                'keys' => $refill->pluck('alert_key')->all(),
                'icon' => 'heroicon-o-truck',
                'tone' => $urgent ? 'rose' : 'amber',
                'items' => $refill->values(),
            ];

            $bundles = $this->unavailableBundles();
            $groups['bundles'] = [
                'key' => 'bundles',
                'label' => 'Paket tidak bisa dipenuhi',
                'hint' => 'Stok komponen tidak cukup untuk satu paket pun',
                'keys' => $bundles->map(fn ($bundle) => (string) $bundle->id)->all(),
                'icon' => 'heroicon-o-gift',
                'tone' => 'rose',
                'items' => $bundles->values(),
            ];
        }

        if ($user->can('sales.view')) {
            $orderGroup = function (string $key, array $meta, $query, callable $fetch) use (&$groups, $read, $limit) {
                $keys = $this->keysOf($query, 'sales_orders.id');
                $groups[$key] = ['key' => $key, ...$meta, 'keys' => $keys,
                    'items' => $this->itemsOf($fetch(clone $query), 'sales_orders.id', $this->unreadOf($read, $key, $keys), $limit)];
            };

            // Pembeli mengajukan batal di Shopee / TikTok saat gudang masih memproses (termasuk
            // yang sudah dikemas & resi dicetak). Paket harus ditahan sampai ada keputusan.
            $orderGroup('cancel_requests', [
                'label' => 'Pembeli minta batal',
                'hint' => 'Tahan paketnya, jangan dikemas atau diserahkan ke kurir. Terima / tolak pembatalan di Seller Center Shopee / TikTok.',
                'icon' => 'heroicon-o-hand-raised',
                'tone' => 'rose',
            ], SalesOrder::query()->cancelRequestedOpen(),
                fn ($q) => $q->with('warehouse:id,code')->orderByRaw('COALESCE(shopee_synced_at, tiktok_synced_at, updated_at) DESC'));

            $hours = (int) config('alerts.pending_order_hours', 24);
            $orderGroup('pending_orders', [
                'label' => 'Pesanan tertunda',
                'hint' => "Dikonfirmasi lebih dari {$hours} jam lalu tapi belum dikirim",
                'icon' => 'heroicon-o-clock',
                'tone' => 'amber',
            ], SalesOrder::query()->whereIn('status', SalesOrder::RESERVING)->where('confirmed_at', '<=', now()->subHours($hours)),
                fn ($q) => $q->with('warehouse:id,code')->oldest('confirmed_at'));

            $courierHours = (int) config('alerts.courier_scan_hours', 24);
            $orderGroup('courier_pending', [
                'label' => 'Paket belum discan kurir',
                'hint' => "Sudah serah terima lebih dari {$courierHours} jam, tapi Shopee / TikTok belum mencatat scan kurir",
                'icon' => 'heroicon-o-truck',
                'tone' => 'rose',
            ], SalesOrder::query()->shopeeFilter('courier_overdue'),
                fn ($q) => $q->with('warehouse:id,code')->oldest('shipped_at'));

            // Pesanan marketplace tertahan karena SKU belum dipetakan: langsung muncul,
            // karena stoknya sudah dikurangi marketplace dan pesanan belum bisa diproses.
            $orderGroup('unmapped_orders', [
                'label' => 'Pesanan dengan SKU belum dipetakan',
                'hint' => 'Masuk dari marketplace tapi ada SKU yang belum terhubung ke produk Optima. Petakan SKU-nya, pesanan dikonfirmasi otomatis.',
                'icon' => 'heroicon-o-link',
                'tone' => 'rose',
            ], SalesOrder::query()->unmappedHeld(),
                fn ($q) => $q->with('warehouse:id,code')->orderByRaw('COALESCE(ordered_at, created_at)'));

            // Pesanan marketplace tertahan karena stok kurang (dicoba ulang otomatis saat stok bertambah).
            $orderGroup('held_orders', [
                'label' => 'Pesanan tertahan stok',
                'hint' => 'Masuk dari marketplace tapi stok gudang kurang. Dikonfirmasi otomatis begitu stok cukup.',
                'icon' => 'heroicon-o-archive-box-x-mark',
                'tone' => 'rose',
            ], SalesOrder::query()->stockHeld(),
                fn ($q) => $q->with(['warehouse:id,code,supply_warehouse_id', 'shopeeShop', 'tiktokShop'])->orderByRaw('COALESCE(ordered_at, created_at)'));

            $retry = app(HeldOrderRetry::class);
            $groups['held_orders']['items']->each(function (SalesOrder $order) use ($retry) {
                $order->shortages = $retry->shortages($order);
                $order->transferFrom = $retry->bestSource($order, $order->shortages);
            });

            $orderGroup('shopee_ahead', [
                'label' => 'Dikirim di Shopee, belum di gudang',
                'hint' => 'Pengiriman otomatis gagal; buka pesanan untuk melihat alasannya',
                'icon' => 'heroicon-o-exclamation-triangle',
                'tone' => 'amber',
            ], SalesOrder::query()->shopeeFilter('shopee_ahead'),
                fn ($q) => $q->with('warehouse:id,code')->oldest('shopee_synced_at'));

            if ($user->can('sales.skip_scan')) {
                $orderGroup('qc_skipped', [
                    'label' => 'Terlewat QC, belum dicek',
                    'hint' => 'Kurir sudah mengambil paket sebelum QC kemas / serah terima; stok keluar dari bin usulan',
                    'icon' => 'heroicon-o-shield-exclamation',
                    'tone' => 'amber',
                ], SalesOrder::query()->qcSkipped('unchecked'),
                    fn ($q) => $q->with('warehouse:id,code')->oldest('shipped_at'));
            }

            $draftHours = (int) config('alerts.draft_order_hours', 24);
            $orderGroup('draft_orders', [
                'label' => 'Pesanan draft belum dikonfirmasi',
                'hint' => "Dibuat lebih dari {$draftHours} jam lalu",
                'icon' => 'heroicon-o-document',
                'tone' => 'slate',
            ], SalesOrder::query()->where('status', 'draft')
                ->whereNull('unmapped_skus') // sudah punya grup sendiri
                ->where('created_at', '<=', now()->subHours($draftHours)),
                fn ($q) => $q->with('warehouse:id,code')->oldest('created_at'));
        }

        if ($user->can('marketplace.view')) {
            $issues = $this->marketplaceIssues();

            $groups['marketplace'] = [
                'key' => 'marketplace',
                'label' => 'Koneksi marketplace',
                'hint' => 'Toko perlu otorisasi ulang, sinkron terhenti, stok tidak dikirim / gagal terkirim, push atau job antrean gagal',
                'keys' => $issues->pluck('alert_key')->all(),
                'icon' => 'heroicon-o-signal-slash',
                'tone' => $issues->contains('severe', true) ? 'rose' : 'amber',
                'items' => $issues,
            ];
        }

        if ($user->can('backups.view')) {
            $backupIssues = $this->backupIssues();

            $groups['backup'] = [
                'key' => 'backup',
                'label' => 'Backup database',
                'hint' => 'Backup harian tidak berjalan atau gagal disalin ke offsite',
                'keys' => $backupIssues->pluck('alert_key')->all(),
                'icon' => 'heroicon-o-circle-stack',
                'tone' => 'rose',
                'items' => $backupIssues,
            ];
        }

        if ($user->can('receipts.view')) {
            $days = (int) config('alerts.draft_receipt_days', 2);
            $receipts = GoodsReceipt::query()
                ->where('status', 'draft')
                ->where('created_at', '<=', now()->subDays($days));
            $keys = $this->keysOf($receipts, 'goods_receipts.id');

            $groups['draft_receipts'] = [
                'key' => 'draft_receipts',
                'label' => 'Penerimaan belum dikonfirmasi',
                'hint' => "Draft lebih dari {$days} hari, stok belum masuk",
                'keys' => $keys,
                'icon' => 'heroicon-o-inbox-arrow-down',
                'tone' => 'slate',
                'items' => $this->itemsOf($receipts->with(['supplier:id,name', 'warehouse:id,code'])->oldest('created_at'),
                    'goods_receipts.id', $this->unreadOf($read, 'draft_receipts', $keys), $limit),
            ];
        }

        if ($user->can('adjustments.approve')) {
            $adjustments = StockAdjustment::query()->where('status', 'pending');
            $keys = $this->keysOf($adjustments, 'stock_adjustments.id');

            $groups['pending_adjustments'] = [
                'key' => 'pending_adjustments',
                'label' => 'Penyesuaian stok menunggu approval',
                'hint' => 'Stok belum berubah sampai disetujui',
                'keys' => $keys,
                'icon' => 'heroicon-o-adjustments-horizontal',
                'tone' => 'amber',
                'items' => $this->itemsOf($adjustments->with(['warehouse:id,code', 'submitter:id,name'])->oldest('submitted_at'),
                    'stock_adjustments.id', $this->unreadOf($read, 'pending_adjustments', $keys), $limit),
            ];
        }

        return array_map(fn (array $group) => $this->withReadState($group, $read[$group['key']] ?? [], $limit), $groups);
    }

    /**
     * Tandai dibaca semua baris yang sedang aktif, untuk satu grup atau semua grup.
     * Kunci lama yang sudah tidak aktif ikut dibuang, jadi kalau masalah yang sama
     * muncul lagi nanti, ia dihitung sebagai notifikasi baru.
     */
    public function markRead(User $user, ?string $group = null): void
    {
        foreach ($this->groups($user, 0) as $key => $data) {
            if ($group !== null && $group !== $key) {
                continue;
            }

            AlertRead::updateOrCreate(['user_id' => $user->id, 'group' => $key], ['item_keys' => $data['keys']]);
        }

        self::forget($user);
    }

    /** Tandai dibaca satu baris notifikasi. */
    public function markItemRead(User $user, string $group, string $itemKey): void
    {
        $row = AlertRead::firstOrNew(['user_id' => $user->id, 'group' => $group]);
        $row->item_keys = array_values(array_unique([...($row->item_keys ?? []), $itemKey]));
        $row->save();

        self::forget($user);
    }

    /** @return array<string, array<string, true>> kunci baris yang sudah dibaca, per grup */
    private function readKeys(User $user): array
    {
        return AlertRead::where('user_id', $user->id)->get(['group', 'item_keys'])
            ->mapWithKeys(fn (AlertRead $row) => [$row->group => array_fill_keys(array_map('strval', $row->item_keys ?? []), true)])
            ->all();
    }

    /** @return list<string> */
    private function keysOf(Builder $query, string $column): array
    {
        return (clone $query)->reorder()->pluck($column)->map(fn ($id) => (string) $id)->all();
    }

    /** @return list<string> kunci baris di grup ini yang belum dibaca */
    private function unreadOf(array $read, string $group, array $keys): array
    {
        $seen = $read[$group] ?? [];

        return array_values(array_filter($keys, fn ($key) => ! isset($seen[$key])));
    }

    /**
     * Contoh baris untuk halaman Notifikasi: yang belum dibaca diambil lebih
     * dulu, sisanya diisi baris lain dengan urutan biasa.
     */
    private function itemsOf(Builder $query, string $column, array $unread, int $limit): Collection
    {
        if (! $limit) {
            return collect();
        }

        // Kalau baris baru sangat banyak, urutan biasa sudah cukup (hampir semua belum dibaca).
        if (! $unread || count($unread) > 500) {
            return $query->limit($limit)->get();
        }

        $first = (clone $query)->whereIn($column, $unread)->limit($limit)->get();
        if ($first->count() >= $limit) {
            return $first;
        }

        return $first->concat((clone $query)->whereNotIn($column, $unread)->limit($limit - $first->count())->get());
    }

    /** Hitung baris belum dibaca dan tandai tiap contoh baris; yang baru tampil di atas. */
    private function withReadState(array $group, array $seen, int $limit): array
    {
        $group['count'] = count($group['keys']);
        $group['unread'] = count(array_filter($group['keys'], fn ($key) => ! isset($seen[$key])));

        $group['items'] = $group['items']
            ->each(function ($item) use ($seen) {
                $key = $item instanceof Model ? (string) $item->getKey() : (string) $item->alert_key;
                $item->alert_key = $key;
                $item->alert_unread = ! isset($seen[$key]);
            })
            ->sortBy(fn ($item) => $item->alert_unread ? 0 : 1)
            ->take($limit)
            ->values();

        return $group;
    }

    /**
     * Backup harian terlewat (penjadwal mati) atau salinan offsite gagal.
     *
     * @return Collection<int, object{shop: string, problem: string, since: ?Carbon, severe: bool, url: string}>
     */
    public function backupIssues(): Collection
    {
        $backup = app(DatabaseBackup::class);
        $issues = collect();
        $url = route('admin.backups.index');
        $latest = $backup->all()->first();

        if (! $latest || $latest['created_at']->lt(now()->subHours(36))) {
            $issues->push((object) [
                'alert_key' => 'backup-daily-'.($latest ? $latest['created_at']->timestamp : 0),
                'shop' => 'Backup harian',
                'problem' => $latest ? 'Tidak ada backup baru sejak '.$latest['created_at']->translatedFormat('d M Y H:i').'. Pastikan penjadwal (cron) berjalan.' : 'Belum ada backup sama sekali.',
                'since' => $latest['created_at'] ?? null,
                'severe' => true,
                'url' => $url,
            ]);
        }

        $offsite = $backup->offsiteEnabled() ? $backup->offsiteStatus() : null;
        if ($offsite && ! $offsite['ok']) {
            $issues->push((object) [
                'alert_key' => 'backup-offsite-'.($offsite['at']?->timestamp ?? 0),
                'shop' => 'Salinan offsite',
                'problem' => 'Gagal disalin ke offsite'.($offsite['error'] ? ': '.$offsite['error'] : ''),
                'since' => $offsite['at'],
                'severe' => true,
                'url' => $url,
            ]);
        }

        return $issues;
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
        // alert_key dipakai untuk tanda "sudah dibaca": angka/waktu ikut di kunci supaya
        // masalah yang bertambah atau terulang dihitung sebagai notifikasi baru.
        $add = fn (string $kind, string $platform, $shop, string $problem, $since, bool $severe, ?string $url = null) => $issues->push((object) [
            'alert_key' => "{$platform}-{$shop->id}-{$kind}",
            'platform' => $platform,
            'shop' => ($platform === 'shopee' ? 'Shopee' : 'TikTok').' · '.$shop->displayName(),
            'problem' => $problem,
            'since' => $since,
            'severe' => $severe,
            'url' => $url ?? route('marketplace.sync-logs', ['shop' => "{$platform}-{$shop->id}"]),
        ]);

        foreach (['shopee' => ShopeeShop::class, 'tiktok' => TiktokShop::class] as $platform => $model) {
            $shops = $model::where('is_active', true)->whereIn('status', ['connected', 'expired'])
                ->withCount([
                    'mappings as failed_stock' => fn ($q) => $q->whereNotNull('stock_error'),
                    'mappings as bundle_like' => fn ($q) => $q->whereNull('removed_at')->bundleLike(),
                ])
                ->get();

            foreach ($shops as $shop) {
                if ($shop->status === 'expired') {
                    $add('expired', $platform, $shop, 'Perlu otorisasi ulang'.($shop->last_error ? ': '.$shop->last_error : ''), $shop->updated_at, true, route('marketplace.shops', ['platform' => $platform]));

                    continue;
                }

                $authEnds = $platform === 'shopee' ? $shop->authorization_expires_at : $shop->refresh_token_expires_at;
                if ($authEnds && $authEnds->lt($authLimit)) {
                    $add('auth-'.$authEnds->format('Ymd'), $platform, $shop, 'Otorisasi berakhir '.$authEnds->translatedFormat('d M Y').'. Hubungkan ulang toko sebelum tanggal itu.', $authEnds, $authEnds->lt(now()->addDays(3)), route('marketplace.shops', ['platform' => $platform]));
                }

                if ($shop->isSyncable() && ($shop->last_synced_at ?? $shop->connected_at)?->lt($stale)) {
                    $add('sync-'.($shop->last_synced_at?->timestamp ?? 0), $platform, $shop, 'Sinkron pesanan terhenti'.($shop->last_error ? ': '.$shop->last_error : ''), $shop->last_synced_at, true);
                }

                // Kirim stok mati: marketplace memakai angka stoknya sendiri, pesanan bisa masuk walau gudang kosong.
                // Pesanan toko masih diproses di sistem lain (cth. Ginee): pengingat saja.
                if (! $shop->process_orders) {
                    $add('external-orders', $platform, $shop, 'Pesanan & retur toko ini masih diproses di sistem lain, jadi tidak ditarik ke Optima dan stok tidak dikirim. Super Admin menyalakan "Proses pesanan di Optima" setelah alamat gudang dipindah.', null, false, route('marketplace.shops', ['platform' => $platform]));
                } elseif (! $shop->stock_push && $shop->warehouse_id) {
                    $add('stock-push-off', $platform, $shop, 'Kirim stok mati: stok di '.($platform === 'shopee' ? 'Shopee' : 'TikTok').' tidak mengikuti Optima, jadi pesanan tetap masuk walau stok gudang habis lalu tertahan "Perlu dicek". Nyalakan setelah stok gudang dicek.', null, false, route('marketplace.shops', ['platform' => $platform]));
                }

                if ($shop->bundle_like > 0) {
                    $add("bundle-like-{$shop->bundle_like}", $platform, $shop, "{$shop->bundle_like} SKU mirip paket dipetakan ke produk biasa, jadi stoknya tidak dihitung dari isi paket", null, false, route("{$platform}.mappings.index", [$shop, 'status' => 'bundle_like']));
                }

                if ($shop->failed_stock > 0) {
                    $add("stock-failed-{$shop->failed_stock}", $platform, $shop, "{$shop->failed_stock} SKU gagal dikirim stoknya", null, false, route('marketplace.products', ['status' => 'error', 'shop' => "{$platform}-{$shop->id}"]));
                }
            }
        }

        $pushes = MarketplaceWebhookEvent::where('status', 'failed')->where('created_at', '>=', now()->subDay())
            ->selectRaw('platform, COUNT(*) as total, MAX(updated_at) as last_at')->groupBy('platform')->get();

        foreach ($pushes as $row) {
            $issues->push((object) [
                'alert_key' => "push-{$row->platform}-".($row->last_at ? Carbon::parse($row->last_at)->timestamp : 0),
                'platform' => $row->platform,
                'shop' => $row->platform === 'shopee' ? 'Push Shopee' : 'Webhook TikTok',
                'problem' => "{$row->total} push gagal diproses dalam 24 jam terakhir (sinkron terjadwal tetap menutupnya)",
                'since' => $row->last_at ? Carbon::parse($row->last_at) : null,
                'severe' => false,
                'url' => route('marketplace.sync-logs', ['tab' => 'push', 'status' => 'failed']),
            ]);
        }

        // Job antrean yang gagal (kirim stok, sinkron toko, dsb.) tidak diulang otomatis.
        $failedJobs = FailedJobs::count(now()->subDays(7));
        if ($failedJobs > 0) {
            $issues->push((object) [
                'alert_key' => 'queue-'.(FailedJobs::lastFailedAt()?->timestamp ?? 0),
                'platform' => 'queue',
                'shop' => 'Antrean latar',
                'problem' => "{$failedJobs} job gagal dalam 7 hari terakhir. Buka untuk melihat penyebab dan mengulanginya.",
                'since' => FailedJobs::lastFailedAt(),
                'severe' => true,
                'url' => route('marketplace.sync-logs', ['tab' => 'jobs']),
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
