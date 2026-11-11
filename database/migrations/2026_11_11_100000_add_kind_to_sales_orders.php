<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang Keluar Manual: barang keluar non-penjualan (sampel, endorse, kirim ke kantor, dll.)
 * memakai alur pesanan yang sama (picking, QC Kemas, serah terima), dibedakan lewat kolom kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('kind', 20)->default('sale')->after('status')->index();
            $table->string('outbound_purpose', 30)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'outbound_purpose']);
        });
    }
};
