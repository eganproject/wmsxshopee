<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scan resi di QC / Serah Terima / Retur dan pencarian pesanan mencari langsung ke
 * tracking_number dan external_number. Tanpa index, setiap scan membaca seluruh
 * tabel pesanan dan makin lambat seiring pesanan bertambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->index('tracking_number');
            $table->index('external_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropIndex(['external_number']);
        });
    }
};
