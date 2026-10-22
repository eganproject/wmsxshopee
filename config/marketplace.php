<?php

return [
    // Kirim stok ke Shopee & TikTok otomatis begitu stok / cadangan pesanan berubah
    // (lewat antrean). Penjadwal juga mengirim perubahan tiap menit sebagai cadangan.
    'instant_stock_push' => (bool) env('MARKETPLACE_INSTANT_STOCK_PUSH', true),

    // Jeda (detik) sebelum stok dikirim, supaya perubahan beruntun terkumpul jadi satu kiriman.
    'stock_push_delay' => (int) env('MARKETPLACE_STOCK_PUSH_DELAY', 15),

    // Jalankan queue worker dari penjadwal tiap menit (cukup untuk Laragon / tanpa
    // Supervisor). Set false bila worker sudah dijalankan Supervisor di VPS.
    'queue_in_scheduler' => (bool) env('QUEUE_IN_SCHEDULER', true),
];
