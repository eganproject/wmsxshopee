<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setiap push Shopee / webhook TikTok yang lolos verifikasi disimpan dulu,
 * baru diproses lewat antrean. Bila gagal, job mengulang sendiri dan jejaknya
 * tetap ada di tabel ini (tidak hilang seperti saat diproses langsung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10);
            $table->string('shop_id', 40);
            // id toko di Optima (shopee_shops / tiktok_shops); shop_id Shopee bisa sama di mode test & live.
            $table->unsignedBigInteger('shop_ref')->nullable();
            $table->unsignedSmallInteger('event_type');
            $table->string('order_id', 64)->nullable();
            $table->json('payload');
            // sha1 isi push: push yang dikirim ulang dengan isi sama tidak diproses dua kali.
            $table->char('fingerprint', 40);
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'fingerprint']);
            $table->index(['status', 'created_at']);
            $table->index(['platform', 'shop_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_webhook_events');
    }
};
