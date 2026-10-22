<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeOrderSync;
use App\Services\Tiktok\TiktokCatalog;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokOrderSync;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan marketplace yang tertahan sebagai draft karena SKU-nya belum dipetakan
 * dihubungkan ulang begitu pemetaan SKU, varian atau paket dengan SKU yang sama
 * ditambahkan. Dijalankan sekali di akhir request / perintah (bukan per baris),
 * jadi impor produk massal tetap ringan.
 */
class MarketplaceOrderRelink
{
    private bool $queued = false;

    public function __construct(
        private readonly ShopeeOrderSync $shopee,
        private readonly TiktokOrderSync $tiktok,
    ) {}

    /** Jadwalkan penghubungan ulang di akhir request (dipanggil dari event model). */
    public function queue(): void
    {
        if ($this->queued) {
            return;
        }

        $this->queued = true;

        app()->terminating(function () {
            try {
                $this->relinkAll();
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $this->queued = false;
            }
        });
    }

    /**
     * Hubungkan ulang semua draft tertahan di semua toko (tanpa panggilan API).
     *
     * @return int pesanan yang kini semua SKU-nya terhubung
     */
    public function relinkAll(): int
    {
        $pending = SalesOrder::withoutGlobalScope('warehouse_access')->where('status', 'draft')->whereNotNull('unmapped_skus');
        $linked = 0;

        $shopeeIds = (clone $pending)->whereNotNull('shopee_shop_id')->distinct()->pluck('shopee_shop_id');
        foreach (ShopeeShop::whereIn('id', $shopeeIds)->get() as $shop) {
            $linked += $this->shopee->relinkPending($shop);
        }

        $tiktokIds = (clone $pending)->whereNotNull('tiktok_shop_id')->distinct()->pluck('tiktok_shop_id');
        foreach (TiktokShop::whereIn('id', $tiktokIds)->get() as $shop) {
            $linked += $this->tiktok->relinkPending($shop);
        }

        return $linked;
    }

    /**
     * Tombol "Sinkronkan ulang": cocokkan SKU otomatis, tarik pesanan terbaru dari
     * marketplace lalu isi & konfirmasi ulang. Bila marketplace tidak bisa dihubungi,
     * draft diisi ulang dari item yang tersimpan.
     *
     * @throws ValidationException
     */
    public function resync(SalesOrder $order): void
    {
        if ($order->shopee_shop_id && $shop = $order->shopeeShop) {
            app(ShopeeCatalog::class)->autoMatch($shop);

            try {
                $this->shopee->syncOne($shop, $order->external_number);
            } catch (ShopeeException $e) {
                $this->fallback($order, $e->getMessage(), fn () => $this->shopee->relink($order, $shop, force: true));
            }

            return;
        }

        if ($order->tiktok_shop_id && $shop = $order->tiktokShop) {
            app(TiktokCatalog::class)->autoMatch($shop);

            try {
                $this->tiktok->syncOne($shop, $order->external_number);
            } catch (TiktokException $e) {
                $this->fallback($order, $e->getMessage(), fn () => $this->tiktok->relink($order, $shop, force: true));
            }

            return;
        }

        throw ValidationException::withMessages(['order' => 'Pesanan ini bukan dari toko marketplace yang terhubung.']);
    }

    private function fallback(SalesOrder $order, string $error, callable $relink): void
    {
        if ($order->status !== 'draft' || $order->marketplace_lines === null) {
            throw ValidationException::withMessages(['order' => 'Gagal menarik pesanan dari marketplace: '.$error]);
        }

        $relink();
    }
}
