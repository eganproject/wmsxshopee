<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Produk paket (bundling): satu SKU jual yang isinya beberapa varian gudang.
        // Paket tidak punya stok sendiri; stok tersedia dihitung dari komponennya.
        // SKU paket unik bersama SKU varian, supaya nanti satu SKU model Shopee
        // bisa dipetakan ke varian atau ke paket tanpa bentrok.
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->string('barcode', 50)->nullable()->unique();
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'product_variant_id']);
        });

        // Baris paket di pesanan. Isinya dipecah jadi baris barang biasa
        // (sales_order_items.sales_order_bundle_id) dengan jumlah = qty paket × qty
        // komponen, jadi cadangan stok, picking dan pengiriman tetap per varian.
        // Harga jual ada di baris paket; baris komponennya berharga 0.
        Schema::create('sales_order_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('price', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->foreignId('sales_order_bundle_id')->nullable()->after('sales_order_id')->constrained()->cascadeOnDelete();
            // Isi per paket saat pesanan dibuat, supaya perubahan isi paket di master
            // tidak mengubah pesanan lama.
            $table->unsignedInteger('bundle_component_qty')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_bundle_id');
            $table->dropColumn('bundle_component_qty');
        });
        Schema::dropIfExists('sales_order_bundles');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
    }
};
