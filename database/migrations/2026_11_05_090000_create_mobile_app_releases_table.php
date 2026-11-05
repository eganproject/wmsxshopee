<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi APK aplikasi Android yang diunggah dari web. Aplikasi mengecek versi
 * terbaru di sini lalu mengunduh & memasang update dari dalam aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name', 30);
            $table->text('changelog')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('source', 20)->default('upload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_releases');
    }
};
