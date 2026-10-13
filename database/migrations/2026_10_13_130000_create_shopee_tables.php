<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris saja: kredensial app Shopee (Seller In House System).
        // Partner key disimpan terenkripsi (APP_KEY), jadi kolomnya text.
        Schema::create('shopee_settings', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 10)->default('test'); // test | live
            $table->unsignedBigInteger('test_partner_id')->nullable();
            $table->text('test_partner_key')->nullable();
            $table->unsignedBigInteger('live_partner_id')->nullable();
            $table->text('live_partner_key')->nullable();
            // Kosong = pakai URL aplikasi (APP_URL). Diisi bila domain yang
            // didaftarkan di Shopee berbeda, cth. domain publik / tunnel.
            $table->string('redirect_base_url')->nullable();
            $table->timestamps();
        });

        // Toko Shopee yang sudah diotorisasi, masing-masing dikirim dari satu gudang.
        Schema::create('shopee_shops', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 10);
            $table->unsignedBigInteger('shop_id');
            $table->string('shop_name')->nullable();
            $table->string('region', 5)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('connected'); // connected | expired | disconnected
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            // Batas otorisasi toko dari Shopee (expire_time di get_shop_info).
            $table->timestamp('authorization_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['environment', 'shop_id']);
        });

        // Pemetaan item/model Shopee ke SKU Optima (varian gudang atau paket).
        Schema::create('shopee_sku_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopee_shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('model_id')->default(0); // 0 = item tanpa variasi
            $table->string('shopee_sku', 100)->nullable();
            $table->string('item_name')->nullable();
            $table->string('model_name')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_type', 10)->nullable(); // auto | manual
            $table->timestamps();

            $table->unique(['shopee_shop_id', 'item_id', 'model_id']);
            $table->index('shopee_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_sku_mappings');
        Schema::dropIfExists('shopee_shops');
        Schema::dropIfExists('shopee_settings');
    }
};
