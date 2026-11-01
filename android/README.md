# Optima Gudang (Android)

Aplikasi Android pendamping Optima. Isinya WebView yang membuka halaman `/m` di server
Optima, ditambah fitur bawaan HP yang tidak bisa dilakukan web biasa:

- Scan barcode/QR dengan kamera (CameraX + ML Kit, bekerja offline).
- Foto dari kamera (bukti paket, surat jalan, retur).
- Cetak ke printer thermal Bluetooth (struk ESC/POS 58/80 mm dan label TSPL).
- Bunyi & getar saat scan berhasil / gagal.

Login, hak akses, aturan stok dan antrean offline semuanya ada di Laravel
(`app/Http/Controllers/Mobile`, `resources/views/mobile`, `resources/js/mobile*`), jadi
perubahan fitur cukup deploy web; APK baru hanya perlu saat bagian native berubah.

## Hak akses

- Akun harus punya hak **Aplikasi Android** (`mobile.access`) untuk login dari aplikasi.
- Menu di beranda mengikuti hak role: QC & serah terima (`sales.fulfill`), penerimaan
  (`receipts.create`), opname (`opname.create`), cek stok rak (`adjustments.create`),
  retur (`returns.create`), cetak label (`products.view` / `warehouses.view`).

## Build & rilis

APK dibangun otomatis oleh GitHub Actions (`.github/workflows/android.yml`):

- Setiap PR: build APK untuk memastikan kode Android masih bisa dikompilasi.
- Setiap push ke `main` yang mengubah `android/**`: build APK rilis lalu terbit sebagai
  GitHub Release `android-v{versionName}` berisi `optima-gudang.apk`.

Halaman web **Aplikasi Android** (`/aplikasi-android`) menautkan APK dari rilis itu
(URL bisa diganti lewat `MOBILE_APK_URL` di `.env`, `{version}` diganti versi).

Untuk rilis baru, naikkan `versionCode` (wajib) dan `versionName` di
`android/version.properties`. Beranda aplikasi menampilkan tombol update otomatis.

## Kunci tanda tangan (sekali saja)

Android hanya mau memasang update di atas versi lama bila APK ditandatangani kunci yang
sama. Tanpa secret di bawah, workflow memakai kunci sementara dan setiap update harus
uninstall dulu.

1. Buat keystore di komputer sendiri (simpan file & password baik-baik, jangan di-commit):

   ```bash
   keytool -genkeypair -v -keystore optima-gudang.jks -alias optima -keyalg RSA -keysize 2048 -validity 10000
   base64 -w0 optima-gudang.jks > keystore.txt
   ```

2. Di GitHub: Settings → Secrets and variables → Actions → New repository secret:
   - `ANDROID_KEYSTORE_BASE64`: isi `keystore.txt`
   - `ANDROID_KEYSTORE_PASSWORD`: password keystore
   - `ANDROID_KEY_ALIAS`: `optima`
   - `ANDROID_KEY_PASSWORD`: password key (sama dengan keystore bila tidak dibedakan)

## Build lokal (opsional)

Butuh JDK 17 dan Android SDK:

```bash
cd android
./gradlew assembleDebug        # app/build/outputs/apk/debug/
```
