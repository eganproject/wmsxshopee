<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris saja: kredensial app TikTok Shop Partner Center (App Key,
        // App Secret terenkripsi & Service ID untuk tautan otorisasi toko).
        Schema::create('tiktok_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_key', 100)->nullable();
            $table->text('app_secret')->nullable();
            $table->string('service_id', 100)->nullable();
            // Kosong = pakai URL aplikasi (APP_URL), seperti di Pengaturan Shopee.
            $table->string('redirect_base_url')->nullable();
            $table->timestamps();
        });

        // Toko TikTok yang sudah diotorisasi. ID toko TikTok berupa angka 19 digit
        // yang dikirim API sebagai teks, jadi disimpan string. shop_cipher wajib
        // ikut di setiap panggilan API toko.
        Schema::create('tiktok_shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id', 40)->unique();
            $table->string('shop_cipher', 150)->nullable();
            $table->string('shop_code', 40)->nullable();
            $table->string('shop_name')->nullable();
            $table->string('region', 5)->nullable();
            $table->string('seller_type', 20)->nullable(); // LOCAL | CROSS_BORDER
            $table->string('open_id', 100)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // Gudang di sisi TikTok tempat stok dikirim (inventory.warehouse_id).
            $table->string('tiktok_warehouse_id', 40)->nullable();
            $table->string('status', 20)->default('connected'); // connected | expired | disconnected
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('orders_synced_until')->nullable();
            $table->timestamp('stock_pushed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pemetaan SKU TikTok (product_id + sku_id) ke SKU Optima.
        Schema::create('tiktok_sku_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_shop_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 40);
            $table->string('sku_id', 40);
            $table->string('seller_sku', 100)->nullable();
            $table->string('product_name')->nullable();
            $table->string('sku_name')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_type', 10)->nullable(); // auto | manual
            $table->integer('stock_pushed')->nullable();
            $table->timestamp('stock_pushed_at')->nullable();
            $table->string('stock_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['tiktok_shop_id', 'sku_id']);
            $table->index('seller_sku');
        });

        // Pesanan dari TikTok Shop: toko asal, status di TikTok, paket & rincian pembayaran.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('tiktok_shop_id')->nullable()->after('shopee_income_synced_at')->constrained()->nullOnDelete();
            $table->string('tiktok_order_status', 30)->nullable()->after('tiktok_shop_id');
            $table->string('tiktok_package_id', 40)->nullable()->after('tiktok_order_status');
            $table->string('tiktok_sync_error', 500)->nullable()->after('tiktok_package_id');
            $table->timestamp('tiktok_synced_at')->nullable()->after('tiktok_sync_error');
            $table->timestamp('tiktok_ship_by')->nullable()->after('tiktok_synced_at');
            $table->timestamp('tiktok_shipped_at')->nullable()->after('tiktok_ship_by');
            // Objek payment pesanan TikTok (subtotal, diskon penjual/platform, ongkir, total).
            $table->json('tiktok_payment')->nullable()->after('tiktok_shipped_at');

            $table->index(['tiktok_shop_id', 'external_number']);
            $table->index(['tiktok_order_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['tiktok_order_status', 'status']);
            $table->dropIndex(['tiktok_shop_id', 'external_number']);
            $table->dropConstrainedForeignId('tiktok_shop_id');
            $table->dropColumn(['tiktok_order_status', 'tiktok_package_id', 'tiktok_sync_error', 'tiktok_synced_at', 'tiktok_ship_by', 'tiktok_shipped_at', 'tiktok_payment']);
        });

        Schema::dropIfExists('tiktok_sku_mappings');
        Schema::dropIfExists('tiktok_shops');
        Schema::dropIfExists('tiktok_settings');
    }
};
