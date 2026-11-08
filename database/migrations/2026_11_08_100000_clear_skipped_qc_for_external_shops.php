<?php

use App\Services\MarketplaceOrderProcessing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toko yang sudah ditandai "diproses di sistem lain" sebelum aturan ini ada:
 * pesanan yang dikirim otomatis ditandai sudah dicek supaya tidak muncul
 * sebagai "Terlewat QC".
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee' => 'shopee_shops', 'tiktok' => 'tiktok_shops'] as $platform => $table) {
            foreach (DB::table($table)->where('process_orders', false)->pluck('id') as $shopId) {
                MarketplaceOrderProcessing::clearSkippedQc($platform, (int) $shopId);
            }
        }
    }

    public function down(): void {}
};
