<?php

/*
|--------------------------------------------------------------------------
| Shopee Open Platform (API v2)
|--------------------------------------------------------------------------
|
| Optima memakai app jenis "Seller In House System": app milik seller sendiri
| untuk toko-toko miliknya, bukan app pihak ketiga (ISV). Partner ID & Key
| diisi dari halaman Integrasi › Pengaturan Shopee dan disimpan terenkripsi.
|
| Alur otorisasi toko:
|   1. Buka {host}/api/v2/shop/auth_partner (ditandatangani partner key).
|   2. Pemilik toko login Shopee Seller lalu menyetujui; Shopee mengarahkan
|      kembali ke callback Optima dengan ?code=...&shop_id=...
|   3. Code ditukar ke /api/v2/auth/token/get -> access_token (± 4 jam) dan
|      refresh_token (± 30 hari, sekali pakai).
|   4. Access token diperbarui lewat /api/v2/auth/access_token/get sebelum
|      kedaluwarsa (perintah shopee:refresh-tokens, terjadwal).
|
*/

return [

    'hosts' => [
        'live' => env('SHOPEE_LIVE_HOST', 'https://partner.shopeemobile.com'),
        'test' => env('SHOPEE_TEST_HOST', 'https://openplatform.sandbox.test-stable.shopee.sg'),
    ],

    // Access token diperbarui bila sisa umurnya di bawah batas ini (menit).
    'refresh_before_minutes' => (int) env('SHOPEE_REFRESH_BEFORE_MINUTES', 60),

    // Batas waktu request ke Shopee (detik).
    'timeout' => (int) env('SHOPEE_TIMEOUT', 20),

    // Jenis label resi yang dipilih bila tersedia: THERMAL_AIR_WAYBILL (printer
    // thermal 100x150) atau NORMAL_AIR_WAYBILL (kertas A4/A6).
    'label_type' => env('SHOPEE_LABEL_TYPE', 'THERMAL_AIR_WAYBILL'),

    // Label dibuat Shopee secara asinkron: cek status sebanyak ini, dengan jeda (ms).
    'document_poll_attempts' => (int) env('SHOPEE_DOCUMENT_POLL_ATTEMPTS', 8),
    'document_poll_ms' => (int) env('SHOPEE_DOCUMENT_POLL_MS', 1000),

];
