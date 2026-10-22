<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salinan item pesanan marketplace & daftar SKU yang belum dipetakan, supaya
     * draft yang tertahan bisa dihubungkan ulang otomatis begitu SKU dipetakan.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->json('marketplace_lines')->nullable()->after('tiktok_payment');
            $table->json('unmapped_skus')->nullable()->after('marketplace_lines');
        });

        // Draft lama yang tertahan SKU belum dipetakan: ditandai supaya ditarik
        // ulang dari marketplace di sinkron pesanan berikutnya.
        DB::table('sales_orders')
            ->where('status', 'draft')
            ->where(fn ($q) => $q->where('shopee_sync_error', 'like', '%belum dipetakan%')->orWhere('tiktok_sync_error', 'like', '%belum dipetakan%'))
            ->update(['unmapped_skus' => '[]']);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['marketplace_lines', 'unmapped_skus']);
        });
    }
};
