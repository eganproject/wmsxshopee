<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen berurutan: <prefix><urutan>, cth. KLR-202610-0001 atau SO2610-001.
 *
 * Urutan terakhir per awalan disimpan di tabel document_sequences, jadi nomor
 * draft yang sudah dihapus tidak dipakai ulang. Nomor terbesar yang ada di tabel
 * dokumen tetap ikut dibandingkan (data lama sebelum tabel urutan ada).
 * Nomor terbesar dicari dari yang terpanjang dulu, supaya "...-10000" terbaca
 * lebih besar dari "...-9999".
 * Dipanggil di dalam transaksi; baris dikunci supaya dua dokumen tidak dapat nomor sama.
 */
class DocumentNumber
{
    public static function next(Builder $query, string $prefix, int $pad): string
    {
        DB::table('document_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0, 'updated_at' => now()]);
        $stored = (int) DB::table('document_sequences')->where('prefix', $prefix)->lockForUpdate()->value('last_number');

        $last = $query->where('number', 'like', "{$prefix}%")
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->value('number');
        $existing = $last ? (int) substr($last, strlen($prefix)) : 0;

        $sequence = max($stored, $existing) + 1;
        DB::table('document_sequences')->where('prefix', $prefix)->update(['last_number' => $sequence, 'updated_at' => now()]);

        return $prefix.str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
    }
}
