<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lokasi bin opsional: stok tanpa bin disimpan di level gudang
     * (warehouse_location_id NULL, ditampilkan sebagai "Tanpa bin").
     */
    public function up(): void
    {
        foreach (['stocks', 'stock_ledgers', 'goods_receipt_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_location_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan ke NOT NULL: baris stok tanpa bin akan gagal.
    }
};
