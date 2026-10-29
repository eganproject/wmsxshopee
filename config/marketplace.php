<?php

return [
    // Kirim stok ke Shopee & TikTok otomatis begitu stok / cadangan pesanan berubah
    // (lewat antrean). Penjadwal juga mengirim perubahan tiap menit sebagai cadangan.
    'instant_stock_push' => (bool) env('MARKETPLACE_INSTANT_STOCK_PUSH', true),

    // Jeda (detik) sebelum stok dikirim, supaya perubahan beruntun terkumpul jadi satu kiriman.
    'stock_push_delay' => (int) env('MARKETPLACE_STOCK_PUSH_DELAY', 15),

    // Pesanan belum dibayar (Shopee UNPAID, TikTok UNPAID / ON_HOLD) menahan stok yang
    // dikirim ke marketplace paling lama sekian jam sejak pesanan dibuat, lalu lepas
    // sendiri bila status batal / dibayar tidak pernah sampai ke Optima.
    'unpaid_hold_hours' => (int) env('MARKETPLACE_UNPAID_HOLD_HOURS', 72),

    // Jalankan queue worker dari penjadwal tiap menit (cukup untuk Laragon / tanpa
    // Supervisor). Set false bila worker sudah dijalankan Supervisor di VPS.
    'queue_in_scheduler' => (bool) env('QUEUE_IN_SCHEDULER', true),

    // Awalan SKU marketplace yang biasanya berarti produk paket. SKU berawalan ini
    // yang dipetakan ke produk biasa (bukan Produk Paket) diberi peringatan, karena
    // stoknya lalu dihitung dari produk itu, bukan dari isi paket. Pisahkan dengan koma.
    'bundle_sku_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARKETPLACE_BUNDLE_SKU_PREFIXES', 'BUND'))))),
];
