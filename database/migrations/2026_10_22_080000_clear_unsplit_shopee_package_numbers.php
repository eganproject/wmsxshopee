<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dulu nomor paket Shopee selalu disimpan, sehingga ikut terkirim ke API logistik dan
 * ditolak untuk pesanan yang tidak dipecah. Kosongkan semuanya; pesanan yang memang
 * dipecah mendapat nomor paketnya lagi saat sinkron pesanan berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_orders')->whereNotNull('shopee_package_number')->update(['shopee_package_number' => null]);
    }

    public function down(): void
    {
        // Tidak bisa dikembalikan; nomor paket diisi ulang oleh sinkron Shopee.
    }
};
