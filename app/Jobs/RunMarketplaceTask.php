<?php

namespace App\Jobs;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\MarketplaceOrderRelink;
use App\Services\MarketplaceProductSync;
use App\Services\MarketplaceShopSync;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeSync;
use App\Services\Tiktok\TiktokCatalog;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokSync;
use App\Support\BackgroundTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sinkron toko marketplace yang dipicu tombol, dijalankan di antrean supaya
 * request web tidak menunggu API marketplace sampai kena 504 dari nginx.
 * Hasilnya dilaporkan ke user lewat BackgroundTasks (toast saat selesai).
 *
 * Jenis tugas:
 * - orders:   pesanan, resi, retur & stok (tombol sinkron di daftar toko)
 * - pull:     tarik produk & SKU + cocokkan otomatis (halaman Pemetaan SKU)
 * - full:     produk lalu pesanan, retur & stok (panel Toko Terhubung di topbar)
 * - products: tarik produk + kirim stok (tombol Sinkron Produk)
 */
class RunMarketplaceTask implements ShouldQueue
{
    use Queueable;

    public const KINDS = [
        'orders' => 'Sinkron pesanan & stok',
        'pull' => 'Tarik produk',
        'full' => 'Sinkron lengkap',
        'products' => 'Sinkron produk',
    ];

    /** Tidak diulang otomatis: user bisa menekan tombolnya lagi. */
    public int $tries = 1;

    public int $timeout = 600;

    /** @param  bool  $option  full: ikut tarik produk; products: ikut kirim stok */
    public function __construct(
        public readonly string $kind,
        public readonly string $platform,
        public readonly int $shopId,
        public readonly int $userId,
        public readonly bool $option = true,
    ) {}

    public static function key(string $kind, string $platform, int $shopId): string
    {
        return "{$kind}:{$platform}:{$shopId}";
    }

    public static function label(string $kind, string $platform, ShopeeShop|TiktokShop $shop): string
    {
        return self::KINDS[$kind].' '.($platform === 'shopee' ? 'Shopee' : 'TikTok').' · '.$shop->displayName();
    }

    /**
     * Masukkan ke antrean. False bila tugas yang sama untuk toko ini masih berjalan.
     */
    public static function start(string $kind, string $platform, ShopeeShop|TiktokShop $shop, int $userId, bool $option = true): bool
    {
        if (! BackgroundTasks::start($userId, self::key($kind, $platform, $shop->id), self::label($kind, $platform, $shop))) {
            return false;
        }

        static::dispatch($kind, $platform, $shop->id, $userId, $option);

        return true;
    }

    /** Pesan flash setelah tugas dimasukkan ke antrean. */
    public static function queuedMessage(int $count = 1): string
    {
        return ($count > 1 ? "{$count} sinkron" : 'Sinkron').' berjalan di latar belakang. Hasilnya muncul di sini saat selesai (biasanya 1-5 menit), Anda bisa lanjut bekerja.';
    }

    public static function busyMessage(): string
    {
        return 'Sinkron yang sama untuk toko ini masih berjalan. Tunggu sampai selesai.';
    }

    public function handle(
        ShopeeSync $shopeeSync,
        TiktokSync $tiktokSync,
        ShopeeCatalog $shopeeCatalog,
        TiktokCatalog $tiktokCatalog,
        MarketplaceShopSync $shopSync,
        MarketplaceProductSync $productSync,
        MarketplaceOrderRelink $relink,
    ): void {
        $shop = ($this->platform === 'shopee' ? ShopeeShop::class : TiktokShop::class)::withoutGlobalScope('warehouse_access')->find($this->shopId);

        if (! $shop) {
            $this->report(false, 'toko sudah dihapus.');

            return;
        }

        try {
            [$ok, $message] = MarketplaceSyncLog::as('manual', fn () => match ($this->kind) {
                'orders' => [true, $this->platform === 'shopee' ? ShopeeSync::summary($shopeeSync->run($shop)) : TiktokSync::summary($tiktokSync->run($shop))],
                'pull' => [true, $this->pull($shop, $shopeeCatalog, $tiktokCatalog, $relink)],
                'full' => $this->fromResult($shopSync->syncShop($this->platform, $shop, $this->option)),
                'products' => $this->fromResult($productSync->syncShop($this->platform, $shop, $this->option)),
            });
        } catch (ShopeeException|TiktokException $e) {
            [$ok, $message] = [false, 'GAGAL, '.$e->getMessage()];
        }

        if ($this->kind === 'products') {
            MarketplaceProductSync::markRun();
        }

        $this->report($ok, $message);
    }

    /** Job mati di tengah jalan (timeout, error tak terduga): tetap kabari user. */
    public function failed(?Throwable $e): void
    {
        $this->report(false, 'GAGAL, proses berhenti di tengah jalan. Cek Riwayat Sinkron lalu coba lagi.');
    }

    private function pull(ShopeeShop|TiktokShop $shop, ShopeeCatalog $shopee, TiktokCatalog $tiktok, MarketplaceOrderRelink $relink): string
    {
        if ($this->platform === 'shopee') {
            $result = $shopee->pull($shop);
            $message = "{$result['items']} produk ({$result['rows']} baris SKU) ditarik dari Shopee, {$result['matched']} cocok otomatis";
        } else {
            $result = $tiktok->pull($shop);
            $message = "{$result['products']} produk ({$result['rows']} baris SKU) ditarik dari TikTok".($result['inactive'] ? " ({$result['inactive']} belum aktif)" : '').", {$result['matched']} cocok otomatis";
        }

        $linked = $relink->relinkAll();

        return $message.MarketplaceProductSync::removedNote($result).'.'.($linked ? " {$linked} pesanan yang tertahan kini terhubung dan diproses." : '');
    }

    /** @return array{0: bool, 1: string} */
    private function fromResult(array $result): array
    {
        return [$result['ok'], $result['ok'] ? ucfirst($result['message']) : 'GAGAL, '.$result['message']];
    }

    private function report(bool $ok, string $message): void
    {
        BackgroundTasks::finish($this->userId, self::key($this->kind, $this->platform, $this->shopId), $ok, $message);
    }
}
