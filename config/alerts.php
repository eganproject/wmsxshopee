<?php

/*
 * Ambang peringatan (lonceng notifikasi & halaman Notifikasi) dan dasar
 * perhitungan saran pembelian ulang. Bisa diubah lewat .env.
 */
return [
    // Pesanan dikonfirmasi tapi belum dikirim lebih dari N jam dianggap tertunda.
    'pending_order_hours' => (int) env('ALERT_PENDING_ORDER_HOURS', 24),

    // Pesanan Shopee yang sudah diserahkan ke kurir tapi lebih dari N jam belum discan kurir (status Shopee masih Diproses).
    'courier_scan_hours' => (int) env('ALERT_COURIER_SCAN_HOURS', 24),

    // Pesanan draft yang belum dikonfirmasi lebih dari N jam.
    'draft_order_hours' => (int) env('ALERT_DRAFT_ORDER_HOURS', 24),

    // Penerimaan barang draft yang belum dikonfirmasi lebih dari N hari.
    'draft_receipt_days' => (int) env('ALERT_DRAFT_RECEIPT_DAYS', 2),

    'reorder' => [
        // Rata-rata penjualan harian dihitung dari pesanan terkirim N hari terakhir.
        'lookback_days' => (int) env('REORDER_LOOKBACK_DAYS', 30),

        // Stok target = stok minimum + rata-rata penjualan harian × N hari.
        'cover_days' => (int) env('REORDER_COVER_DAYS', 30),
    ],

    // Lama ringkasan lonceng disimpan di cache (detik) supaya tidak menghitung ulang tiap halaman.
    'cache_seconds' => (int) env('ALERT_CACHE_SECONDS', 60),
];
