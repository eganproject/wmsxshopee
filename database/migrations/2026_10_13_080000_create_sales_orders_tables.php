<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pesanan penjualan manual (input form atau impor Excel/CSV). Alur status:
        // draft -> confirmed (stok dicadangkan) -> picked -> packed -> shipped (stok keluar
        // lewat mutasi Barang Keluar). Batal melepas cadangan stok.
        // channel_name sementara teks bebas; nanti saat API Shopee masuk ditambah shop_id.
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('status', 20)->default('draft');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->string('channel_name', 100)->nullable();
            $table->string('external_number', 100)->nullable();
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30)->nullable();
            $table->text('shipping_address')->nullable();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier_service', 50)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'order_date']);
            $table->index(['channel_name', 'external_number']);
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('price', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Hasil picking: dari lokasi bin mana saja tiap baris diambil (satu baris
        // pesanan bisa diambil dari beberapa bin). Lokasi null = stok "Tanpa bin".
        Schema::create('sales_order_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_picks');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
