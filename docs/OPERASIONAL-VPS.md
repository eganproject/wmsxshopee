# Operasional VPS: backup offsite & Redis

Dua pengaturan opsional di server. Keduanya hanya lewat `.env`, tanpa ubah kode.

## 1. Backup offsite (disarankan)

Backup harian (`db:backup`, jam `BACKUP_TIME`) tersimpan di `storage/app/backups`.
Bila VPS rusak, file itu ikut hilang, jadi salin juga ke penyimpanan S3-compatible.
Pilihan murah: Cloudflare R2 (gratis 10 GB) atau Backblaze B2 (gratis 10 GB).

1. Buat bucket privat, lalu buat API key yang hanya boleh baca/tulis bucket itu.
2. Isi `.env`:

   ```env
   BACKUP_OFFSITE_DISK=offsite
   BACKUP_OFFSITE_FOLDER=optima-live      # bedakan dengan sandbox, mis. optima-test
   BACKUP_OFFSITE_KEEP_DAYS=30
   BACKUP_S3_KEY=...
   BACKUP_S3_SECRET=...
   BACKUP_S3_BUCKET=nama-bucket
   BACKUP_S3_REGION=auto                  # R2: auto · B2: mis. us-west-004
   BACKUP_S3_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
   ```

3. `php artisan config:cache`, lalu coba: `sudo -u www-data php artisan db:backup`.
   Keluaran harus berisi "Disalin ke offsite". Halaman Pengaturan → Backup Database
   menampilkan status salinan terakhir; bila gagal, lonceng notifikasi memberi tahu.

## 2. Redis untuk cache & antrean (opsional)

Cache dan antrean defaultnya disimpan di MySQL. Dengan Redis, MySQL lebih ringan
dan antrean lebih cepat. Driver PHP (`predis`) sudah ikut di composer.

1. `sudo apt install redis-server` lalu `sudo systemctl enable --now redis-server`.
2. Tunggu antrean kosong (Riwayat Sinkron tidak menampilkan antrean menunggu), lalu di `.env`:

   ```env
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   ```

   Prefix Redis otomatis memakai nama database, jadi Live (`optisync.tech`) dan
   Sandbox (`test.optisync.tech`) tidak saling mengambil job walau satu redis-server.
3. Di file Supervisor worker, ganti `queue:work database` menjadi `queue:work redis` (opsi lain biarkan),
   lalu `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart all`.
4. `php artisan config:cache`.

Untuk kembali ke MySQL, kembalikan kedua nilai ke `database` dan perintah worker ke `queue:work database`.
