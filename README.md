# Optima

**Optima** adalah Warehouse Management System (WMS) yang terhubung dengan Shopee Open Platform untuk mengelola stok, produk dan pesanan dari beberapa toko Shopee sekaligus.

Dibangun dengan Laravel 12, Blade, Tailwind CSS dan Alpine.js.

## Fitur saat ini

- Login dengan email atau username
- Manajemen user
- Role & permission (spatie/laravel-permission)
- Profil pengguna
- Master produk: SKU internal, varian, barcode, satuan, berat, dimensi dan gambar produk
- Stok per varian per lokasi bin, mutasi stok (masuk, keluar, transfer lokasi, penyesuaian) dan kartu stok dengan saldo berjalan
- Cetak label barcode (Code128 / QR) untuk SKU produk, produk paket dan lokasi bin, di roll thermal atau lembar stiker A4

Rencana tahap berikutnya ada di [docs/ROADMAP.md](docs/ROADMAP.md).

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link   # agar gambar produk bisa tampil
npm run build
```

Nama dan tagline aplikasi diatur lewat `.env`:

```dotenv
APP_NAME=Optima
APP_TAGLINE="Warehouse Management System"
```

## Testing

```bash
php artisan test
```
