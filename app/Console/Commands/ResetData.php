<?php

namespace App\Console\Commands;

use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mulai bersih tanpa koneksi ulang ke Shopee / TikTok: hapus semua data transaksi
 * (pesanan, stok, mutasi, penerimaan, transfer, retur, log), tapi pertahankan
 * user, pengaturan marketplace, toko beserta tokennya, gudang, kurir, supplier,
 * produk dan pemetaan SKU. Pesanan ditarik ulang dari marketplace di sinkron berikutnya.
 */
class ResetData extends Command
{
    protected $signature = 'optima:reset-data
        {--days=7 : Pesanan berapa hari terakhir yang ditarik ulang dari marketplace (1-15)}
        {--with-products : Ikut hapus produk, varian & paket (pemetaan SKU dikosongkan, toko tetap terhubung)}
        {--no-backup : Lewati backup database sebelum menghapus}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Hapus data transaksi untuk mulai bersih, tanpa memutus koneksi toko Shopee / TikTok';

    /** Data transaksi yang dihapus, anak dulu baru induk (urutan foreign key). */
    public const TRANSACTION_TABLES = [
        'sales_return_items', 'sales_returns',
        'sales_order_picks', 'sales_order_items', 'sales_order_bundles', 'sales_orders', 'shipment_handovers',
        'goods_receipt_items', 'goods_receipts',
        'stock_count_items', 'stock_counts',
        'stock_adjustment_items', 'stock_adjustments',
        'damaged_document_items', 'damaged_documents', 'damaged_stock_ledgers', 'damaged_stocks',
        'warehouse_transfer_items', 'warehouse_transfers',
        'stock_ledgers', 'stock_movement_items', 'stock_movements', 'stocks',
        'lost_items', 'marketplace_stock_holds', 'marketplace_webhook_events', 'marketplace_sync_logs',
        'activity_logs', 'document_sequences', 'jobs', 'failed_jobs',
    ];

    /** Ikut dihapus dengan --with-products. */
    public const PRODUCT_TABLES = [
        'bundle_items', 'bundles', 'warehouse_variant_locations', 'warehouse_variant_settings',
        'product_images', 'product_variants', 'products',
    ];

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 15) {
            $this->error('--days harus 1 sampai 15.');

            return self::FAILURE;
        }

        $withProducts = (bool) $this->option('with-products');
        $tables = array_values(array_filter(
            [...self::TRANSACTION_TABLES, ...($withProducts ? self::PRODUCT_TABLES : [])],
            fn (string $table) => Schema::hasTable($table),
        ));

        $this->warn('Data berikut akan DIHAPUS PERMANEN:');
        $this->line('  Pesanan, stok & mutasi, penerimaan, transfer, opname, penyesuaian, retur, barang rusak, log aktivitas & sinkron.');
        if ($withProducts) {
            $this->line('  Produk, varian, paket, lokasi tetap & min/max SKU (pemetaan SKU marketplace dikosongkan).');
        }
        $this->info('Yang dipertahankan: user & hak akses, pengaturan & toko Shopee/TikTok (tanpa koneksi ulang), gudang & lokasi, kurir, supplier'.($withProducts ? '.' : ', produk & pemetaan SKU.'));
        $this->line("Setelah itu: kirim stok semua toko DIMATIKAN (stok jadi 0), pesanan {$days} hari terakhir ditarik ulang di sinkron berikutnya.");

        if (! $this->option('force') && ! $this->confirm('Lanjutkan?')) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        if (! $this->option('no-backup')) {
            $this->info('Membuat backup database dulu...');
            if (Artisan::call('db:backup') !== self::SUCCESS) {
                $this->error('Backup gagal, tidak ada yang dihapus. '.trim(Artisan::output()));

                return self::FAILURE;
            }
            $this->line(trim(Artisan::output()));
        }

        DB::transaction(function () use ($tables, $withProducts) {
            // Pemetaan dilepas dulu supaya produk bisa dihapus.
            $mappingReset = ['stock_pushed' => null, 'stock_pushed_at' => null, 'stock_error' => null];
            $unlink = $withProducts ? ['product_variant_id' => null, 'bundle_id' => null, 'match_type' => null] : [];
            ShopeeSkuMapping::query()->update([...$mappingReset, ...$unlink]);
            TiktokSkuMapping::query()->update([...$mappingReset, ...$unlink]);

            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        });

        // Stok kosong tidak boleh terkirim ke marketplace; nyalakan lagi setelah stok awal dicatat.
        $from = now()->subDays($days);
        $shopReset = ['stock_push' => false, 'orders_synced_until' => $from, 'returns_synced_until' => null, 'last_error' => null];
        ShopeeShop::query()->update($shopReset);
        TiktokShop::query()->update($shopReset);

        $this->info('Selesai. '.count($tables).' tabel dikosongkan, toko tetap terhubung.');
        $this->line('Berikutnya: catat stok awal (Stok Awal / penerimaan), cek Pemetaan SKU, lalu nyalakan lagi "Kirim stok" per toko.');

        return self::SUCCESS;
    }
}
