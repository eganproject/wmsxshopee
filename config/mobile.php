<?php

return [
    /*
    | Alamat download APK Optima Gudang. {version} diganti versionName dari
    | android/version.properties. Default: GitHub Release yang dibuat otomatis
    | oleh workflow Android setiap ada perubahan aplikasi di branch main.
    */
    'apk_url' => env('MOBILE_APK_URL', 'https://github.com/eganproject/wmsxshopee/releases/download/android-v{version}/optima-gudang.apk'),
];
