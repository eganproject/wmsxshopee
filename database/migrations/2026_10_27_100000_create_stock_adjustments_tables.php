<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen Penyesuaian Stok dengan approval: draft -> menunggu approval ->
 * disetujui (stok berubah lewat mutasi Penyesuaian) atau ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('draft');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('reason', 30);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            // Mutasi Penyesuaian yang tercatat saat disetujui.
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'adjustment_date']);
            $table->index(['warehouse_id', 'adjustment_date']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            // Kosong = stok "Tanpa bin" di level gudang.
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->restrictOnDelete();
            // Bertanda: + menambah, - mengurangi.
            $table->integer('qty');
            // Saldo lokasi tepat sebelum disetujui, untuk jejak audit.
            $table->integer('qty_before')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
