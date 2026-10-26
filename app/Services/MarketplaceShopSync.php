<?php

namespace App\Services;

use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Models\User;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeSync;
use App\Services\Tiktok\TiktokCatalog;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron lengkap toko marketplace dari panel "Toko Terhubung" di topbar:
 * produk & SKU (tarik + cocokkan otomatis), lalu pesanan & resi, retur, dan
 * stok. Produk ditarik lebih dulu supaya pesanan dengan SKU baru langsung
 * terpetakan dan stok yang dikirim mencakup SKU baru.
 */
class MarketplaceShopSync
{
    public function __construct(
        private readonly ShopeeCatalog $shopeeCatalog,
        private readonly TiktokCatalog $tiktokCatalog,
        private readonly ShopeeSync $shopeeSync,
        private readonly TiktokSync $tiktokSync,
    ) {}

    /**
     * Semua toko yang boleh dilihat user, untuk panel di topbar.
     *
     * @return Collection<int, array{platform: string, shop: ShopeeShop|TiktokShop}>
     */
    public static function shopsFor(User $user): Collection
    {
        $shopee = $user->can('shopee.view')
            ? ShopeeShop::with('warehouse:id,code,name')->orderBy('shop_name')->get()->map(fn ($shop) => ['platform' => 'shopee', 'shop' => $shop])
            : collect();
        $tiktok = $user->can('tiktok.view')
            ? TiktokShop::with('warehouse:id,code,name')->orderBy('shop_name')->get()->map(fn ($shop) => ['platform' => 'tiktok', 'shop' => $shop])
            : collect();

        return $shopee->concat($tiktok)->values();
    }

    /** User boleh menekan tombol sinkron untuk toko ini. */
    public static function canSync(User $user, string $platform, ShopeeShop|TiktokShop $shop): bool
    {
        return $user->can("{$platform}.sync") && $user->canAccessWarehouse($shop->warehouse_id);
    }

    /**
     * Status ringkas toko: [kunci, label]. Kunci dipakai untuk warna titik.
     *
     * @return array{0: string, 1: string}
     */
    public static function status(ShopeeShop|TiktokShop $shop): array
    {
        return match (true) {
            ! $shop->is_active => ['inactive', 'Nonaktif'],
            ! $shop->isConnected() => ['expired', 'Perlu otorisasi ulang'],
            ! $shop->warehouse_id => ['attention', 'Gudang pengirim belum dipilih'],
            filled($shop->last_error) => ['attention', 'Sinkron terakhir gagal'],
            default => ['connected', 'Terhubung'],
        };
    }

    /**
     * Sinkron semua toko aktif & terhubung yang boleh disinkron user.
     *
     * @return list<array{platform: string, shop: string, ok: bool, message: string}>
     */
    public function syncAll(User $user): array
    {
        return self::shopsFor($user)
            ->filter(fn ($row) => $row['shop']->is_active && $row['shop']->isConnected() && self::canSync($user, $row['platform'], $row['shop']))
            ->map(fn ($row) => $this->syncShop($row['platform'], $row['shop'], $user->can("{$row['platform']}.mapping")))
            ->values()
            ->all();
    }

    /** @return array{platform: string, shop: string, ok: bool, message: string} */
    public function syncShop(string $platform, ShopeeShop|TiktokShop $shop, bool $pullProducts = true): array
    {
        $label = ($platform === 'shopee' ? 'Shopee' : 'TikTok').' · '.$shop->displayName();

        if (! $shop->is_active || ! $shop->isConnected()) {
            return ['platform' => $platform, 'shop' => $label, 'ok' => false, 'message' => 'toko nonaktif atau perlu otorisasi ulang'];
        }

        $parts = [];

        try {
            if ($pullProducts) {
                $pulled = $platform === 'shopee' ? $this->shopeeCatalog->pull($shop) : $this->tiktokCatalog->pull($shop);
                $parts[] = 'produk: '.($pulled['items'] ?? $pulled['products']).' ('.$pulled['rows'].' SKU)'.(($pulled['inactive'] ?? 0) ? ", {$pulled['inactive']} belum aktif di TikTok" : '').', '.$pulled['matched'].' cocok otomatis'.MarketplaceProductSync::removedNote($pulled);
            }

            if ($shop->isSyncable()) {
                $result = $platform === 'shopee' ? $this->shopeeSync->run($shop) : $this->tiktokSync->run($shop);
                $parts[] = lcfirst(rtrim($platform === 'shopee' ? ShopeeSync::summary($result) : TiktokSync::summary($result), '.'));
            } else {
                $parts[] = 'pesanan & stok belum disinkron (gudang pengirim belum dipilih)';
            }
        } catch (ShopeeException|TiktokException $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $e->needsReauthorization() ? 'expired' : $shop->status]);
            Log::warning('Sinkron toko marketplace gagal', ['platform' => $platform, 'shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);

            return ['platform' => $platform, 'shop' => $label, 'ok' => false, 'message' => $e->getMessage()];
        }

        if ($shop->last_error) {
            $shop->update(['last_error' => null]);
        }

        return ['platform' => $platform, 'shop' => $label, 'ok' => true, 'message' => implode('; ', $parts)];
    }

    /**
     * Ringkasan satu baris untuk pesan flash.
     *
     * @param  list<array{platform: string, shop: string, ok: bool, message: string}>  $results
     */
    public static function summary(array $results): string
    {
        if ($results === []) {
            return 'Belum ada toko marketplace yang terhubung, aktif dan bisa Anda sinkron.';
        }

        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return 'Sinkron '.count($results).' toko selesai'.($failed ? ", {$failed} gagal" : '').'. '
            .implode(' | ', array_map(fn ($r) => "{$r['shop']}: ".($r['ok'] ? '' : 'GAGAL, ').$r['message'], $results));
    }
}
