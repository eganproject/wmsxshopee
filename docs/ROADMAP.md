# Roadmap Optima

Urutan pengerjaan WMS sebelum dan sesudah integrasi Shopee Open Platform.

| Tahap | Isi | Status |
| --- | --- | --- |
| 1 | Master data gudang: gudang, zona, rak, lokasi bin | Selesai |
| 2 | Master produk: SKU internal, varian, barcode, satuan, berat, dimensi, gambar | Selesai |
| 3 | Data toko (manual, tanpa token) | **Ditunda** sampai API Shopee siap (lihat catatan di bawah) |
| 4 | Stok dan kartu stok: barang masuk, keluar, transfer antar lokasi, penyesuaian | Sedang dikerjakan |
| 5 | Penerimaan barang (inbound): PO / surat jalan, cek jumlah, simpan ke rak | Belum |
| 6 | Pesanan manual: input / impor Excel, picking, packing, pengiriman | Belum |
| 7 | Stock opname dan laporan: stok per lokasi, stok minimum, pergerakan barang | Belum |
| — | Integrasi Shopee API: hubungkan toko, pemetaan produk, tarik pesanan | Belum |

## TODO: Tahap 3, data toko (ditunda)

Diputuskan menunggu sampai integrasi API Shopee dikerjakan, lalu dibuat bersamaan.

Isi yang direncanakan, per toko:

- Nama toko
- Shop ID Shopee
- Nama seller / pemilik
- Gudang pengirim (relasi ke master gudang)
- Kurir yang dipakai (relasi ke master kurir)
- Status aktif

Tujuannya agar pesanan (tahap 6) dan pemetaan produk Shopee (`item_id` / `model_id`
ke varian) bisa ditandai "milik toko mana". Saat API siap, tombol
"Hubungkan ke Shopee" (OAuth, simpan access token & refresh token) ditambahkan di
data toko yang sama, tanpa membuat ulang tabelnya.

Yang perlu diperhatikan saat tahap 3 dikerjakan:

- Pesanan manual (tahap 6) kalau dikerjakan lebih dulu sebaiknya sudah punya kolom
  `shop_id` nullable, supaya bisa diisi begitu data toko ada.
- Satu varian bisa terhubung ke banyak toko (multi seller), jadi pemetaan produk
  disimpan di tabel terpisah per toko, bukan di `product_variants`.
