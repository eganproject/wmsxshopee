<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toko TikTok yang sempat ditandai "Perlu otorisasi ulang" hanya karena batas
 * panggilan API (Too many requests, 36009002) dikembalikan ke status terhubung:
 * token & otorisasinya tidak pernah dicabut.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tiktok_shops')
            ->where('status', 'expired')
            ->whereNotNull('refresh_token')
            ->where(fn ($q) => $q->where('last_error', 'like', '%36009002%')
                ->orWhere('last_error', 'like', '%36009004%')
                ->orWhere('last_error', 'like', '%oo many request%'))
            ->update(['status' => 'connected', 'updated_at' => now()]);
    }

    public function down(): void {}
};
