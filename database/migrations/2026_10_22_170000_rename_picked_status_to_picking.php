<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status pesanan "Sudah Diambil" (picked) berganti nama menjadi "Sedang Diambil"
 * (picking), begitu juga kolom waktunya. Alur dan datanya tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_orders')->where('status', 'picked')->update(['status' => 'picking']);

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->renameColumn('picked_at', 'picking_at');
        });
    }

    public function down(): void
    {
        DB::table('sales_orders')->where('status', 'picking')->update(['status' => 'picked']);

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->renameColumn('picking_at', 'picked_at');
        });
    }
};
