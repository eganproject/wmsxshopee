<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Penjelasan alasan gagal kirim stok ke marketplace, plus langkah perbaikannya,
 * untuk tooltip "gagal kirim stok" dan kolom stok di halaman Pemetaan SKU.
 */
class StockPushError
{
    /**
     * @return array{title: string, fix: string}
     */
    public static function explain(string $platform, ?string $error): array
    {
        $name = $platform === 'shopee' ? 'Shopee' : 'TikTok';
        $error = (string) $error;

        return match (true) {
            (bool) preg_match('/not[\s_-]*(found|exist)|does ?n.?t exist|no such|invalid[\s_-]*(item|model|sku|product)|(item|model|sku|product)[\s_-]*(id )?(is )?invalid|deleted|tidak ditemukan|tidak ada/i', $error) => [
                'title' => "SKU sudah tidak ada di {$name}",
                'fix' => "Variasi/SKU ini kemungkinan dihapus atau diganti di {$name}. Klik Tarik dari {$name} di halaman Pemetaan SKU (atau tunggu Sinkron Produk otomatis tiap jam): baris yang sudah tidak ada ditandai \"Tidak ada di toko\" dan tidak dihitung gagal lagi.",
            ],
            (bool) preg_match('/promo|campaign|flash|discount|reserved|lock/i', $error) => [
                'title' => "Stok sedang dikunci {$name}",
                'fix' => 'SKU ini sedang ikut promosi/flash sale sehingga stoknya tidak bisa diubah. Optima mencoba lagi otomatis; bila promosi sudah selesai, klik Kirim Ulang Stok Gagal di Riwayat Sinkron.',
            ],
            (bool) preg_match('/token|auth|permission|forbidden|unauthori/i', $error) => [
                'title' => 'Akses toko ditolak',
                'fix' => "Klik tombol \"Perbarui token & info toko\" (ikon panah melingkar) di daftar toko. Bila tetap gagal, hubungkan ulang toko dari {$name}.",
            ],
            (bool) preg_match('/warehouse|gudang/i', $error) => [
                'title' => "Gudang {$name} tidak cocok",
                'fix' => "Cek gudang pengiriman toko di {$name} Seller Center, lalu klik Tarik dari {$name} supaya gudangnya diperbarui.",
            ],
            default => [
                'title' => "Ditolak {$name}",
                'fix' => "Optima mencoba lagi otomatis. Bila tetap gagal, cek produk ini di {$name} Seller Center, lalu klik Kirim Ulang Stok Gagal di Riwayat Sinkron.",
            ],
        };
    }

    /**
     * Isi tooltip untuk daftar baris pemetaan yang gagal kirim stok:
     * SKU mana, alasannya, dan langkah perbaikannya.
     *
     * @param  Collection<int, Model>  $rows  ShopeeSkuMapping / TiktokSkuMapping
     * @return array{title: string, body: string, next: string}
     */
    public static function summary(string $platform, Collection $rows, int $limit = 5): array
    {
        $lines = $rows->take($limit)->map(fn (Model $row) => '• '.self::label($platform, $row).': '.$row->stock_error);

        if ($rows->count() > $limit) {
            $lines->push('… dan '.($rows->count() - $limit).' SKU lain');
        }

        $reasons = $rows->map(fn (Model $row) => self::explain($platform, $row->stock_error))->unique('title')->values();
        $single = $reasons->count() === 1;

        return [
            'title' => $rows->count().' SKU gagal kirim stok'.($single ? ' · '.$reasons[0]['title'] : ''),
            'body' => $lines->implode("\n"),
            'next' => $single ? $reasons[0]['fix'] : 'Klik untuk melihat alasan dan solusi tiap SKU.',
        ];
    }

    /** "POD1 (Produk A · Merah)" untuk satu baris pemetaan. */
    public static function label(string $platform, Model $row): string
    {
        $sku = ($platform === 'shopee' ? $row->shopee_sku : $row->seller_sku) ?? '(tanpa SKU)';
        $name = collect($platform === 'shopee' ? [$row->item_name, $row->model_name] : [$row->product_name, $row->sku_name])->filter()->implode(' · ');

        return $name !== '' ? "{$sku} ({$name})" : $sku;
    }
}
