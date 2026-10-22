<?php

namespace App\Services\Shopee;

use App\Models\SalesOrder;

/**
 * package_number hanya boleh dikirim ke API logistik Shopee untuk pesanan yang dipecah
 * (split) jadi beberapa paket. Pesanan biasa ditolak Shopee bila package_number ikut
 * terkirim ("Please don't request with package_number for this unsplit order").
 */
class ShopeePackage
{
    /** Nomor paket yang disimpan, hanya bila pesanan Shopee benar-benar dipecah. */
    public static function fromDetail(array $detail): ?string
    {
        $packages = $detail['package_list'] ?? [];
        $split = ! empty($detail['split_up']) || count($packages) > 1;

        return $split ? ($packages[0]['package_number'] ?? null) : null;
    }

    /**
     * Jalankan panggilan API dengan package_number pesanan; bila Shopee menolak karena
     * pesanan tidak dipecah (data lama yang terlanjur menyimpan nomor paket), nomor paket
     * dihapus dari pesanan lalu panggilan diulang tanpa package_number.
     *
     * @template T
     *
     * @param  callable(array<string, string>): T  $call  menerima ['package_number' => ...] atau []
     * @return T
     */
    public static function call(SalesOrder $order, callable $call): mixed
    {
        $package = filled($order->shopee_package_number) ? ['package_number' => (string) $order->shopee_package_number] : [];

        try {
            return $call($package);
        } catch (ShopeeException $e) {
            if ($package === [] || ! self::isUnsplitError($e)) {
                throw $e;
            }

            $order->update(['shopee_package_number' => null]);

            return $call([]);
        }
    }

    public static function isUnsplitError(ShopeeException $e): bool
    {
        // Kode error Shopee memang salah eja: "..._not_need_pacakge_number".
        return (bool) preg_match('/not_need_pa\w*_number/i', (string) $e->shopeeError.' '.$e->getMessage());
    }
}
