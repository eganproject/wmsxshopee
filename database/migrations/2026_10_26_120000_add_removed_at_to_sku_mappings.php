<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai baris pemetaan SKU yang sudah tidak ada lagi di toko marketplace
 * (SKU/variasi dihapus atau diganti). Barisnya tidak dihapus supaya pesanan
 * & retur lama tetap bisa dikenali, tapi tidak lagi dikirim stoknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('removed_at')->nullable()->after('stock_error')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['removed_at']);
                $table->dropColumn('removed_at');
            });
        }
    }
};
