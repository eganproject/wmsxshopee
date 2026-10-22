<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Riwayat sinkron per toko (pesanan, retur, stok, produk): kapan, oleh apa, hasil & error. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10);
            $table->unsignedBigInteger('shop_ref');
            $table->string('shop_name', 150);
            $table->string('part', 40);
            $table->string('trigger', 12);
            $table->string('status', 10);
            $table->string('summary', 500)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['platform', 'shop_ref', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_logs');
    }
};
