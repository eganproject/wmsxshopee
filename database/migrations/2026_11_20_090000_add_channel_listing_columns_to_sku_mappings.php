<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman Produk Channel: data listing marketplace yang ikut ditarik saat
 * sinkron produk, bukan hanya nama & SKU: harga jual, stok yang terlihat di
 * marketplace, status tayang, dan gambar produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('channel_price', 15, 2)->nullable()->after('match_type');
                $table->integer('channel_stock')->nullable()->after('channel_price');
                $table->string('channel_status', 20)->nullable()->after('channel_stock');
                $table->string('image_url', 500)->nullable()->after('channel_status');
            });
        }
    }

    public function down(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['channel_price', 'channel_stock', 'channel_status', 'image_url']);
            });
        }
    }
};
