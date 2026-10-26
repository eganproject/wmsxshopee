<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar "Kirim stok" per toko. Toko yang baru dihubungkan mulai dengan kirim stok mati,
 * supaya stok asli di Shopee/TikTok tidak tertimpa stok Optima sebelum stok gudang dicek.
 * Toko yang sudah terhubung tetap mengirim stok seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_shops', 'tiktok_shops'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('stock_push')->default(false)->after('is_active');
            });

            DB::table($table)->update(['stock_push' => true]);
        }
    }

    public function down(): void
    {
        foreach (['shopee_shops', 'tiktok_shops'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('stock_push'));
        }
    }
};
