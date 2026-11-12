<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tanda "sudah dibaca" per user per grup notifikasi. Notifikasi dihitung
        // langsung dari data, jadi yang disimpan hanya kunci baris yang sudah dibaca;
        // baris yang belum ada di daftar ini dihitung sebagai notifikasi baru.
        Schema::create('alert_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('group', 40);
            $table->json('item_keys');
            $table->timestamps();

            $table->unique(['user_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_reads');
    }
};
