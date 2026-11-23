<?php

namespace App\Http\Controllers\Concerns;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\MarketplaceShopSync;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokStockSync;
use App\Support\SkuLookup;
use App\Support\SkuMappingProblems;
use App\Support\StockPushError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tombol penyelesaian di card "Masalah pemetaan" halaman Pemetaan SKU
 * (dipakai controller Shopee & TikTok; route model binding tetap di controller).
 */
trait ResolvesSkuMappingProblems
{
    /** 'shopee' / 'tiktok'. */
    abstract protected function platform(): string;

    /**
     * Petakan ulang SKU yang dipetakan ke SKU Optima berbeda padahal ada
     * varian/paket dengan SKU persis sama (mengikuti aturan Cocokkan Otomatis).
     */
    protected function fixProblem(Request $request, ShopeeShop|TiktokShop $shop): RedirectResponse
    {
        $request->validate(['problem' => ['required', Rule::in(['sku_conflict'])]]);

        $platform = $this->platform();
        $column = SkuMappingProblems::skuColumn($platform);
        $lookup = new SkuLookup;
        $fixed = 0;

        SkuMappingProblems::scope($shop->mappings()->getQuery(), 'sku_conflict', $platform)->get()
            ->each(function (ShopeeSkuMapping|TiktokSkuMapping $mapping) use ($lookup, $column, &$fixed) {
                [$variantId, $bundleId] = $lookup->find($mapping->{$column});

                if ($variantId || $bundleId) {
                    $mapping->update(['product_variant_id' => $variantId, 'bundle_id' => $bundleId, 'match_type' => 'auto', 'stock_error' => null]);
                    $fixed++;
                }
            });

        return back()->with('success', $fixed
            ? "{$fixed} SKU dipetakan ulang mengikuti SKU yang sama di gudang. Stok terbarunya dikirim pada kiriman stok berikutnya.".$this->relinkOrders()
            : 'Tidak ada SKU yang perlu dipetakan ulang.');
    }

    /** Kirim ulang stok toko ini sekarang, untuk SKU yang gagal kirim. */
    protected function retryStockFor(Request $request, ShopeeShop|TiktokShop $shop): RedirectResponse
    {
        $platform = $this->platform();
        abort_unless(MarketplaceShopSync::canSync($request->user(), $platform, $shop), 403, 'Anda tidak punya izin menyinkron toko ini.');

        if (! $shop->isSyncable()) {
            return back()->with('error', 'Toko belum bisa disinkron: pastikan toko aktif, terhubung, dan gudang pengirimnya sudah dipilih.');
        }

        if (! $shop->stock_push) {
            return back()->with('error', 'Kirim stok untuk toko ini sedang dimatikan. Nyalakan dulu di daftar toko.');
        }

        @set_time_limit(600);

        try {
            $result = MarketplaceSyncLog::track($platform, $shop, 'stock',
                fn () => $shop instanceof ShopeeShop ? app(ShopeeStockSync::class)->push($shop) : app(TiktokStockSync::class)->push($shop),
                fn (array $r) => [$r['failed'] ? 'failed' : 'success', 'Kirim ulang: '.($r['models'] ?? $r['skus']).' SKU dikirim'.($r['failed'] ? ', '.count($r['failed']).' gagal' : '')]);
        } catch (ShopeeException|TiktokException $e) {
            return back()->with('error', 'Kirim ulang stok gagal: '.$e->getMessage());
        }

        $left = count($result['failed']);

        if ($left === 0) {
            return back()->with('success', 'Stok dikirim ulang dan semua SKU diterima '.($platform === 'shopee' ? 'Shopee' : 'TikTok').'.');
        }

        $why = StockPushError::summary($platform, SkuMappingProblems::scope($shop->mappings()->getQuery(), 'failed', $platform)->get());

        return back()->with('error', "Stok dikirim ulang, {$left} SKU masih ditolak. {$why['next']}");
    }

    /** SKU mirip paket yang memang bukan paket: jangan dihitung masalah lagi. */
    protected function markNotBundle(ShopeeShop|TiktokShop $shop, ShopeeSkuMapping|TiktokSkuMapping $mapping): RedirectResponse
    {
        $mapping->update(['bundle_like_ignored_at' => now()]);

        return back()->with('success', 'SKU '.$mapping->{SkuMappingProblems::skuColumn($this->platform())}.' ditandai bukan paket dan tidak dihitung masalah lagi.');
    }
}
