<?php

/*
|--------------------------------------------------------------------------
| TikTok Shop Open API (Partner Center, API versi 202309)
|--------------------------------------------------------------------------
|
| App dibuat di partner.tiktokshop.com. App Key, App Secret & Service ID
| diisi dari halaman Integrasi › Pengaturan Marketplace (tab TikTok) (secret disimpan terenkripsi).
| Sejak versi 202309 TikTok tidak punya sandbox terpisah: uji memakai toko uji
| (test shop) dari Partner Center dengan host yang sama.
|
| Alur otorisasi toko:
|   1. Buka {authorize}?service_id=...&state=... ; pemilik toko login TikTok
|      Shop Seller Center lalu menyetujui.
|   2. TikTok mengarahkan kembali ke callback Optima dengan ?code=...&state=...
|   3. Code ditukar di {auth}/api/v2/token/get -> access_token (± 7 hari) dan
|      refresh_token (lebih lama); masa berlaku dikirim sebagai unix timestamp.
|   4. Toko yang diotorisasi diambil dari /authorization/202309/shops (cipher
|      wajib dikirim di setiap panggilan API toko).
|   5. Access token diperbarui di {auth}/api/v2/token/refresh (terjadwal).
|
*/

return [

    'hosts' => [
        'api' => env('TIKTOK_API_HOST', 'https://open-api.tiktokglobalshop.com'),
        'auth' => env('TIKTOK_AUTH_HOST', 'https://auth.tiktok-shops.com'),
        'authorize' => env('TIKTOK_AUTHORIZE_URL', 'https://services.tiktokshop.com/open/authorize'),
    ],

    'version' => env('TIKTOK_API_VERSION', '202309'),

    // Access token diperbarui bila sisa umurnya di bawah batas ini (menit).
    'refresh_before_minutes' => (int) env('TIKTOK_REFRESH_BEFORE_MINUTES', 24 * 60),

    // Batas waktu request ke TikTok (detik).
    'timeout' => (int) env('TIKTOK_TIMEOUT', 20),

    // Batas waktu membuka koneksi (detik). Bila server tidak menjawab dalam waktu ini,
    // panggilan GET diulang (lihat retries) lalu dicoba lagi di sinkron berikutnya.
    'connect_timeout' => (int) env('TIKTOK_CONNECT_TIMEOUT', 10),

    // Gangguan sementara (batas panggilan, server sibuk, putus koneksi saat GET) diulang
    // sebanyak ini dengan jeda bertahap sebelum dianggap gagal.
    'retries' => (int) env('TIKTOK_RETRIES', 3),

    // Jarak minimal antar panggilan API dalam satu proses (milidetik), supaya
    // kirim stok / tarik produk yang beruntun tidak kena "Too many requests".
    'min_interval_ms' => (int) env('TIKTOK_MIN_INTERVAL_MS', 200),

    // Atur pengiriman & cetak resi banyak pesanan: sebanyak ini panggilan ke TikTok
    // dikirim bersamaan (paralel), bukan satu per satu.
    'concurrency' => (int) env('TIKTOK_CONCURRENCY', 4),

    // Ukuran label resi PDF dari TikTok: A6 (thermal 100x150) atau A5.
    'label_size' => env('TIKTOK_LABEL_SIZE', 'A6'),

];
