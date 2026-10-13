<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retur penjualan: paket kembali dari pembeli. Dicari lewat resi / no. SO /
        // no. pesanan channel (source = order), atau dicatat tanpa pesanan (source =
        // manual, cth. reseller / toko offline). Draft masih bisa diubah; saat diterima,
        // barang layak jual masuk stok lewat satu mutasi Barang Masuk dan dokumen terkunci.
        // external_return_number disiapkan untuk no. return/refund dari API Shopee.
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('status', 20)->default('draft');
            $table->string('source', 20)->default('order');
            $table->foreignId('sales_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('tracking_number', 100)->nullable();
            $table->string('sender_name', 150);
            $table->string('sender_phone', 30)->nullable();
            $table->string('channel_name', 100)->nullable();
            $table->string('external_number', 100)->nullable();
            $table->string('external_return_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'return_date']);
            $table->index('tracking_number');
            $table->index(['channel_name', 'external_return_number']);
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            // Baris pesanan asal (retur dari pesanan); null untuk retur tanpa pesanan.
            $table->foreignId('sales_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            // Hanya qty_good (layak jual) yang masuk stok; qty_damaged dicatat terpisah.
            $table->unsignedInteger('qty_good')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 30);
            $table->string('photo_path')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
