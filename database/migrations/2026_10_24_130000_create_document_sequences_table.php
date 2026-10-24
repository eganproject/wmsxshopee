<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urutan terakhir per awalan nomor dokumen (cth. "SO2610-"), supaya nomor
 * draft yang dihapus tidak dipakai lagi oleh dokumen berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('prefix', 40)->primary();
            $table->unsignedBigInteger('last_number');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
