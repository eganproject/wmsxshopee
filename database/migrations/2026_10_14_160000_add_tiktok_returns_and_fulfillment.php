<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retur TikTok ditarik ke draft Retur Penjualan seperti retur Shopee.
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('tiktok_shop_id')->nullable()->after('shopee_return_status')->constrained()->nullOnDelete();
            $table->string('tiktok_return_status', 50)->nullable()->after('tiktok_shop_id');
        });

        Schema::table('tiktok_shops', function (Blueprint $table) {
            $table->timestamp('returns_synced_until')->nullable()->after('orders_synced_until');
        });

        // Pembeli mengajukan pembatalan (is_buyer_request_cancel): gudang menahan paket.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('tiktok_cancel_requested')->default(false)->after('tiktok_order_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('tiktok_cancel_requested');
        });

        Schema::table('tiktok_shops', function (Blueprint $table) {
            $table->dropColumn('returns_synced_until');
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiktok_shop_id');
            $table->dropColumn('tiktok_return_status');
        });
    }
};
