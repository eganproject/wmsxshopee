<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Batas peringatan stok minimum per varian (null = tanpa peringatan).
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('min_stock')->nullable()->after('height_cm');
        });

        // Stock opname: lembar hitung per gudang (opsional per zona), selisih
        // diposting sebagai mutasi Penyesuaian lewat StockService.
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('draft');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // Cakupan: all = semua bin + tanpa bin, zone = bin di satu zona, unbinned = hanya tanpa bin.
            $table->string('scope', 20)->default('all');
            $table->foreignId('warehouse_zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('count_date');
            $table->text('notes')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'count_date']);
        });

        // Satu baris = satu varian di satu lokasi (NULL = "Tanpa bin").
        // system_qty: saldo saat lembar dibuat, diperbarui ke saldo aktual saat posting.
        // target_location_id: stok tanpa bin yang dihitung bisa sekalian ditaruh ke bin.
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('target_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->integer('system_qty')->default(0);
            $table->integer('counted_qty')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_count_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('min_stock');
        });
    }
};
