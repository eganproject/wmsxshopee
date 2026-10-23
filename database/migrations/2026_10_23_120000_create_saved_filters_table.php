<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pencarian tersimpan per user: kombinasi filter daftar (mis. Pesanan) yang bisa dipakai ulang sekali klik. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page', 40);
            $table->string('name', 60);
            $table->json('query');
            $table->timestamps();

            $table->index(['user_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
