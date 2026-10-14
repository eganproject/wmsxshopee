<?php

/*
|--------------------------------------------------------------------------
| TikTok Shop Open API (Partner Center, API versi 202309)
|--------------------------------------------------------------------------
|
| App dibuat di partner.tiktokshop.com. App Key, App Secret & Service ID
| diisi dari halaman Integrasi › Pengaturan TikTok (secret disimpan terenkripsi).
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

];
