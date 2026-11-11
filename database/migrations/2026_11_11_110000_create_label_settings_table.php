<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan cetak resi (satu baris): QR nomor resi (AWB) yang ditempel di
 * label Shopee / TikTok dan digambar di label pesanan manual. Posisi QR
 * diatur per platform karena tata letak label tiap marketplace berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('qr_enabled')->default(true);
            $table->unsignedTinyInteger('qr_size_mm')->default(18);
            foreach (['shopee', 'tiktok'] as $platform) {
                $table->boolean("{$platform}_qr")->default(true);
                $table->string("{$platform}_qr_position", 20)->default('bottom-right');
                $table->decimal("{$platform}_qr_margin_x_mm", 5, 1)->default(4);
                $table->decimal("{$platform}_qr_margin_y_mm", 5, 1)->default(4);
            }
            $table->boolean('manual_qr')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_settings');
    }
};
