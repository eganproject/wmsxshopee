<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar "Proses pesanan di Optima" per toko. Mati: pesanan toko masih diproses
 * di sistem lain (cth. Ginee), jadi Optima tidak menarik pesanan & retur toko itu.
 * Hanya Super Admin yang boleh mengubahnya. Semua toko mulai menyala.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_shops', 'tiktok_shops'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('process_orders')->default(true)->after('stock_push');
            });
        }
    }

    public function down(): void
    {
        foreach (['shopee_shops', 'tiktok_shops'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('process_orders'));
        }
    }
};
