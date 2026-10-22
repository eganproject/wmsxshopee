<?php

namespace App\Services;

use App\Jobs\PushMarketplaceStock;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokStockSync;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Kirim stok ke semua toko Shopee & TikTok yang siap disinkron.
 *
 * Mode perubahan (default) hanya mengirim SKU yang stok jualnya beda dari yang
 * terakhir terkirim, jadi aman dijalankan sesering mungkin: tiap ada perubahan
 * stok / pesanan (lewat antrean) dan tiap menit dari penjadwal.
 */
class MarketplaceStockPush
{
    private ?float $lastQueuedAt = null;

    public function __construct(
        private readonly ShopeeStockSync $shopee,
        private readonly TiktokStockSync $tiktok,
    ) {}

    /** Jadwalkan kirim stok setelah ada perubahan (dipanggil dari event model). */
    public function queue(): void
    {
        if (! config('marketplace.instant_stock_push')) {
            return;
        }

        // Banyak baris stok berubah dalam satu proses (cth. penerimaan barang): cukup sekali.
        if ($this->lastQueuedAt !== null && microtime(true) - $this->lastQueuedAt < 5) {
            return;
        }

        $this->lastQueuedAt = microtime(true);

        try {
            PushMarketplaceStock::dispatch()
                ->delay(now()->addSeconds(config('marketplace.stock_push_delay')))
                ->afterCommit();
        } catch (\Throwable $e) {
            // Antrean bermasalah tidak boleh menggagalkan transaksi gudang; penjadwal tetap mengirim tiap menit.
            report($e);
        }
    }

    /**
     * @return list<array{shop: string, sent: int, failed: list<string>}>
     */
    public function run(bool $onlyChanged = true): array
    {
        $results = [];

        foreach ($this->shops() as $shop) {
            $name = ($shop instanceof ShopeeShop ? 'Shopee ' : 'TikTok ').$shop->displayName();

            try {
                $result = $shop instanceof ShopeeShop
                    ? $this->shopee->push($shop, onlyChanged: $onlyChanged)
                    : $this->tiktok->push($shop, onlyChanged: $onlyChanged);

                $results[] = ['shop' => $name, 'sent' => $result['models'] ?? $result['skus'] ?? 0, 'failed' => $result['failed']];
            } catch (ShopeeException|TiktokException $e) {
                $shop->update([
                    'last_error' => mb_substr('Kirim stok: '.$e->getMessage(), 0, 500),
                    'status' => $e->needsReauthorization() ? 'expired' : $shop->status,
                ]);
                $results[] = ['shop' => $name, 'sent' => 0, 'failed' => [$e->getMessage()]];
                Log::warning('Kirim stok marketplace gagal', ['shop' => $name, 'error' => $e->getMessage()]);
            } catch (LockTimeoutException) {
                // Toko ini sedang dikirim stoknya oleh proses lain; giliran berikutnya menyusul.
                $results[] = ['shop' => $name, 'sent' => 0, 'failed' => []];
            }
        }

        return $results;
    }

    /** @return list<ShopeeShop|TiktokShop> */
    private function shops(): array
    {
        $ready = fn ($query) => $query->where('status', 'connected')->where('is_active', true)->whereNotNull('warehouse_id');

        return [
            ...$ready(ShopeeShop::query())->get()->filter->isSyncable()->values()->all(),
            ...$ready(TiktokShop::query())->get()->filter->isSyncable()->values()->all(),
        ];
    }
}
