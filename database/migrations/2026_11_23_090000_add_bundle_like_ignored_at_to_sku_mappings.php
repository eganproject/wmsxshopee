<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card "Masalah pemetaan" di halaman Pemetaan SKU: baris SKU mirip paket yang
 * memang bukan paket bisa ditandai "Bukan paket" supaya tidak dihitung masalah lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('bundle_like_ignored_at')->nullable()->after('removed_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['shopee_sku_mappings', 'tiktok_sku_mappings'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('bundle_like_ignored_at');
            });
        }
    }
};
