<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasil kirim stok terakhir per SKU Shopee: jumlah yang dikirim, kapan,
        // dan alasan bila ditolak Shopee. Dipakai halaman Status Produk Shopee.
        Schema::table('shopee_sku_mappings', function (Blueprint $table) {
            $table->integer('stock_pushed')->nullable()->after('match_type');
            $table->timestamp('stock_pushed_at')->nullable()->after('stock_pushed');
            $table->string('stock_error', 255)->nullable()->after('stock_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_sku_mappings', function (Blueprint $table) {
            $table->dropColumn(['stock_pushed', 'stock_pushed_at', 'stock_error']);
        });
    }
};
