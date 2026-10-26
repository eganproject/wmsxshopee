<?php

namespace App\Services;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\Tiktok\TiktokCatalog;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokStockSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron produk semua toko marketplace sekaligus: tarik daftar produk & SKU
 * dari tiap toko Shopee/TikTok yang terhubung, cocokkan otomatis dengan SKU
 * Optima, lalu kirim stok tersedia untuk SKU yang sudah terpetakan.
 * Dijalankan scheduler tiap jam dan dari tombol "Sinkron Produk".
 */
class MarketplaceProductSync
{
    public const PLATFORMS = ['shopee', 'tiktok'];

    private const LAST_RUN_KEY = 'marketplace.products_synced_at';

    public function __construct(
        private readonly ShopeeCatalog $shopeeCatalog,
        private readonly ShopeeStockSync $shopeeStock,
        private readonly TiktokCatalog $tiktokCatalog,
        private readonly TiktokStockSync $tiktokStock,
    ) {}

    /**
     * @param  list<string>  $platforms
     * @param  (callable(ShopeeShop|TiktokShop): bool)|null  $canPushStock  batasi toko yang stoknya boleh dikirim
     * @return list<array{platform: string, shop: string, ok: bool, message: string}>
     */
    public function run(array $platforms = self::PLATFORMS, ?callable $canPushStock = null): array
    {
        $results = [];

        foreach ($this->shops($platforms) as [$platform, $shop]) {
            $results[] = $this->syncShop($platform, $shop, $canPushStock === null || $canPushStock($shop));
        }

        Cache::forever(self::LAST_RUN_KEY, now()->toIso8601String());

        return $results;
    }

    public static function lastRunAt(): ?Carbon
    {
        $value = Cache::get(self::LAST_RUN_KEY);

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Toko yang terhubung dan aktif. Gudang pengirim tidak wajib untuk menarik
     * produk, hanya untuk mengirim stok.
     *
     * @param  list<string>  $platforms
     * @return list<array{0: string, 1: ShopeeShop|TiktokShop}>
     */
    private function shops(array $platforms): array
    {
        $rows = [];

        if (in_array('shopee', $platforms, true)) {
            foreach (ShopeeShop::where('status', 'connected')->where('is_active', true)->orderBy('shop_name')->get() as $shop) {
                $rows[] = ['shopee', $shop];
            }
        }
        if (in_array('tiktok', $platforms, true)) {
            foreach (TiktokShop::where('status', 'connected')->where('is_active', true)->orderBy('shop_name')->get() as $shop) {
                $rows[] = ['tiktok', $shop];
            }
        }

        return array_values(array_filter($rows, fn ($row) => $row[1]->isConnected()));
    }

    /** @return array{platform: string, shop: string, ok: bool, message: string} */
    public function syncShop(string $platform, ShopeeShop|TiktokShop $shop, bool $pushStock = true): array
    {
        $label = ($platform === 'shopee' ? 'Shopee' : 'TikTok').' · '.$shop->displayName();

        try {
            [$message, $stock, $sent] = MarketplaceSyncLog::track($platform, $shop, 'products', function () use ($platform, $shop, $pushStock) {
                if ($platform === 'shopee') {
                    $pulled = $this->shopeeCatalog->pull($shop);
                    $message = "{$pulled['items']} produk ({$pulled['rows']} SKU) ditarik, {$pulled['matched']} cocok otomatis".self::removedNote($pulled);
                    $stock = $pushStock && $shop->isSyncable() && $shop->stock_push ? $this->shopeeStock->push($shop) : null;
                    $sent = $stock['models'] ?? 0;
                } else {
                    $pulled = $this->tiktokCatalog->pull($shop);
                    $message = "{$pulled['products']} produk ({$pulled['rows']} SKU) ditarik".($pulled['inactive'] ? " ({$pulled['inactive']} belum aktif di TikTok)" : '').", {$pulled['matched']} cocok otomatis".self::removedNote($pulled);
                    $stock = $pushStock && $shop->isSyncable() && $shop->stock_push ? $this->tiktokStock->push($shop) : null;
                    $sent = $stock['skus'] ?? 0;
                }

                return [$message, $stock, $sent];
            }, fn (array $result) => [($result[1]['failed'] ?? []) ? 'failed' : 'success', $result[0].($result[1] !== null ? "; stok {$result[2]} SKU dikirim".(($result[1]['failed'] ?? []) ? ', '.count($result[1]['failed']).' gagal' : '') : '')]);
        } catch (ShopeeException|TiktokException $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $e->needsReauthorization() ? 'expired' : $shop->status]);
            Log::warning('Sinkron produk marketplace gagal', ['platform' => $platform, 'shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);

            return ['platform' => $platform, 'shop' => $label, 'ok' => false, 'message' => $e->getMessage()];
        }

        $message .= match (true) {
            $stock === null && ! $shop->warehouse_id => '; stok belum dikirim (gudang pengirim belum dipilih)',
            $stock === null => '',
            default => "; stok {$sent} SKU dikirim".($stock['failed'] ? ', '.count($stock['failed']).' gagal' : ''),
        };

        return ['platform' => $platform, 'shop' => $label, 'ok' => true, 'message' => $message];
    }

    /** Catatan SKU yang ditandai sudah tidak ada di toko saat tarik produk. */
    public static function removedNote(array $pulled): string
    {
        return ($pulled['removed'] ?? 0) ? ", {$pulled['removed']} SKU sudah tidak ada di toko (tidak dikirim stoknya lagi)" : '';
    }

    /** Ringkasan satu baris untuk pesan flash. */
    public static function summary(array $results): string
    {
        if ($results === []) {
            return 'Belum ada toko marketplace yang terhubung dan aktif.';
        }

        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return 'Sinkron produk '.count($results).' toko selesai'.($failed ? ", {$failed} gagal" : '').'. '
            .implode(' | ', array_map(fn ($r) => "{$r['shop']}: ".($r['ok'] ? '' : 'GAGAL, ').$r['message'], $results));
    }
}
