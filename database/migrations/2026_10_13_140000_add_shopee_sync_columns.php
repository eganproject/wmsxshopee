<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pesanan yang ditarik dari Shopee: toko asal, status terakhir di Shopee
        // dan catatan bila belum bisa diproses (SKU belum dipetakan, stok kurang).
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('shopee_shop_id')->nullable()->after('external_number')->constrained()->nullOnDelete();
            $table->string('shopee_order_status', 30)->nullable()->after('shopee_shop_id');
            $table->string('shopee_package_number', 50)->nullable()->after('shopee_order_status');
            $table->string('shopee_sync_error', 500)->nullable()->after('shopee_package_number');
            $table->timestamp('shopee_synced_at')->nullable()->after('shopee_sync_error');

            $table->index(['shopee_shop_id', 'external_number']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('shopee_shop_id')->nullable()->after('external_return_number')->constrained()->nullOnDelete();
            $table->string('shopee_return_status', 30)->nullable()->after('shopee_shop_id');
        });

        // Penanda sinkron per toko: pesanan & retur ditarik bertahap mulai dari
        // waktu terakhir; stok dikirim berkala.
        Schema::table('shopee_shops', function (Blueprint $table) {
            $table->timestamp('orders_synced_until')->nullable()->after('last_synced_at');
            $table->timestamp('returns_synced_until')->nullable()->after('orders_synced_until');
            $table->timestamp('stock_pushed_at')->nullable()->after('returns_synced_until');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_shops', function (Blueprint $table) {
            $table->dropColumn(['orders_synced_until', 'returns_synced_until', 'stock_pushed_at']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopee_shop_id');
            $table->dropColumn('shopee_return_status');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['shopee_shop_id', 'external_number']);
            $table->dropConstrainedForeignId('shopee_shop_id');
            $table->dropColumn(['shopee_order_status', 'shopee_package_number', 'shopee_sync_error', 'shopee_synced_at']);
        });
    }
};
