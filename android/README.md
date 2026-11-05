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
(URL bisa diganti lewat `MOBILE_APK_URL` di `.env`, `{version}` diganti versi) selama
belum ada versi yang diunggah.

## Update dari dalam aplikasi

1. Naikkan `versionCode` (wajib, harus lebih besar) dan `versionName` di
   `android/version.properties`, merge ke `main`, tunggu GitHub Actions selesai.
2. Deploy web seperti biasa, lalu buka **Aplikasi Android → Upload Versi Baru**
   (hak `mobile.releases`). Pilih **Ambil dari GitHub Release** (server mengunduh APK
   sendiri, tidak terkena batas upload) atau upload file `.apk`. Isi catatan perubahan
   dan nyalakan **Wajib update** bila versi lama tidak boleh dipakai lagi.
3. Saat aplikasi dibuka, halaman `/m` mengecek `/m/app-version`. Bila ada versi baru,
   muncul layar **Update tersedia** dengan catatan perubahan. **Update sekarang**
   mengunduh APK di dalam aplikasi, mencocokkan checksum, nama paket dan nomor build,
   lalu membuka layar pasang Android. Pertama kali, Android meminta izin
   *Instal aplikasi tidak dikenal* untuk Optima Gudang.
4. Versi wajib menutup aplikasi sampai update dipasang, termasuk bila HP melewatkan
   versi wajib lalu ada versi biasa sesudahnya.

APK disimpan di `storage/app/private/mobile-releases` dan diunduh lewat link bertanda
tangan (berlaku 1 hari dari aplikasi, 7 hari dari halaman web), jadi browser HP dan
aplikasi versi 1.0.0 (yang belum bisa update sendiri) tetap bisa mengunduhnya.

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
