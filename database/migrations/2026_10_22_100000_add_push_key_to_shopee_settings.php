<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee menandatangani Live Push dengan "Live Push Partner Key" (menu Push
 * Mechanism), bukan partner key app. Simpan key itu per mode, plus jejak push
 * terakhir supaya kegagalan verifikasi kelihatan di halaman pengaturan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->text('test_push_key')->nullable()->after('live_partner_key');
            $table->text('live_push_key')->nullable()->after('test_push_key');
            $table->timestamp('last_push_at')->nullable();
            $table->string('last_push_result', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['test_push_key', 'live_push_key', 'last_push_at', 'last_push_result']);
        });
    }
};
