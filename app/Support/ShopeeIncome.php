<?php

namespace App\Support;

/**
 * Susunan rincian pendapatan Shopee (order_income dari get_escrow_detail) untuk
 * ditampilkan. Angka diambil apa adanya dari Shopee; total "Dana diterima"
 * memakai escrow_amount dari Shopee, bukan hasil hitung ulang Optima.
 */
class ShopeeIncome
{
    /** [kunci order_income, label, tanda]; tanda -1 = memotong pendapatan penjual. */
    public const SECTIONS = [
        'Harga & potongan penjual' => [
            ['original_price', 'Harga asli produk', 1],
            ['seller_discount', 'Diskon produk dari penjual', -1],
            ['voucher_from_seller', 'Voucher toko', -1],
            ['seller_coin_cash_back', 'Cashback koin dari penjual', -1],
        ],
        'Ongkos kirim' => [
            ['buyer_paid_shipping_fee', 'Ongkir dibayar pembeli', 1],
            ['shopee_shipping_rebate', 'Subsidi ongkir dari Shopee', 1],
            ['shipping_fee_discount_from_3pl', 'Diskon ongkir dari kurir', 1],
            ['actual_shipping_fee', 'Ongkir sebenarnya', -1],
            ['seller_shipping_discount', 'Subsidi ongkir dari penjual', -1],
        ],
        'Biaya Shopee' => [
            ['commission_fee', 'Biaya administrasi', -1],
            ['service_fee', 'Biaya layanan', -1],
            ['seller_transaction_fee', 'Biaya transaksi', -1],
            ['order_ams_commission_fee', 'Komisi affiliate (AMS)', -1],
            ['escrow_tax', 'Pajak', -1],
        ],
        'Penyesuaian' => [
            ['seller_return_refund', 'Pengembalian dana ke pembeli', 1],
            ['seller_lost_compensation', 'Kompensasi barang hilang', 1],
            ['drc_adjustable_refund', 'Penyesuaian refund', 1],
        ],
    ];

    /** Ditanggung Shopee: tidak memotong pendapatan, hanya informasi. */
    public const SHOPEE_FUNDED = [
        ['shopee_discount', 'Diskon dari Shopee'],
        ['voucher_from_shopee', 'Voucher Shopee'],
        ['coins', 'Koin Shopee dipakai pembeli'],
        ['payment_promotion', 'Promo metode bayar'],
    ];

    /**
     * Baris yang bernilai (bukan 0) per bagian.
     *
     * @param  array<string, mixed>  $income
     * @return array<string, list<array{label: string, amount: float}>>
     */
    public static function sections(array $income): array
    {
        $result = [];

        foreach (self::SECTIONS as $title => $rows) {
            foreach ($rows as [$key, $label, $sign]) {
                $value = (float) ($income[$key] ?? 0);

                if ($value != 0.0) {
                    // Shopee kadang mengirim potongan sebagai angka negatif; tampilkan tanda yang konsisten.
                    $result[$title][] = ['label' => $label, 'amount' => $sign * abs($value)];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $income
     * @return list<array{label: string, amount: float}>
     */
    public static function shopeeFunded(array $income): array
    {
        return collect(self::SHOPEE_FUNDED)
            ->map(fn ($row) => ['label' => $row[1], 'amount' => abs((float) ($income[$row[0]] ?? 0))])
            ->filter(fn ($row) => $row['amount'] != 0.0)
            ->values()->all();
    }

    /**
     * Total potongan biaya Shopee (admin + layanan + transaksi + AMS + pajak).
     *
     * @param  array<string, mixed>  $income
     */
    public static function fees(array $income): float
    {
        return collect(self::SECTIONS['Biaya Shopee'])->sum(fn ($row) => abs((float) ($income[$row[0]] ?? 0)));
    }
}
