<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dokumen mutasi stok: barang masuk, keluar, transfer antar lokasi & penyesuaian.
        // Dokumen tidak bisa diubah/dihapus setelah disimpan; koreksi lewat penyesuaian.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('type', 20);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('movement_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'movement_date']);
        });

        Schema::create('stock_movement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            // Selalu positif, kecuali penyesuaian (bertanda: + tambah, - kurang).
            $table->integer('qty');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Saldo stok saat ini per varian per lokasi bin.
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->integer('qty')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_location_id']);
            $table->index(['warehouse_id', 'warehouse_location_id']);
        });

        // Kartu stok: satu baris per perubahan saldo di satu lokasi. Saldo berjalan
        // dihitung saat ditampilkan (urut tanggal mutasi), jadi tetap benar walau
        // ada mutasi yang dicatat mundur tanggal.
        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_item_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('movement_date');
            $table->unsignedInteger('qty_in')->default(0);
            $table->unsignedInteger('qty_out')->default(0);
            $table->timestamps();

            $table->index(['product_variant_id', 'movement_date', 'id']);
            $table->index(['warehouse_location_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledgers');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('stock_movement_items');
        Schema::dropIfExists('stock_movements');
    }
};
