<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Nama user disalin supaya log tetap terbaca walau akunnya dihapus.
            $table->string('user_name')->nullable();
            $table->string('module', 40)->index();
            $table->string('event', 20)->index();
            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();
            $table->string('description');
            // {"old": {...}, "new": {...}} berisi nilai yang sudah siap tampil.
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
