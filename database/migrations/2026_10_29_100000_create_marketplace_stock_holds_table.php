<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cadangan sementara untuk pesanan marketplace yang belum dibayar: hanya
        // mengurangi stok yang dikirim ke marketplace, bukan stok gudang.
        Schema::create('marketplace_stock_holds', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10);
            $table->unsignedBigInteger('shop_id');
            $table->string('order_ref', 64);
            $table->string('order_status', 30)->nullable();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['platform', 'shop_id', 'order_ref', 'product_variant_id'], 'marketplace_stock_holds_order_variant');
            $table->index(['warehouse_id', 'product_variant_id', 'expires_at'], 'marketplace_stock_holds_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_stock_holds');
    }
};
