<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Nomor dokumen berurutan: <prefix><urutan>, cth. KLR-202610-0001 atau SO2610-001.
 *
 * Urutan terakhir dicari dari nomor terpanjang dulu, baru urutan teks, supaya
 * setelah 9999 (atau 999) nomor 10000 tetap terbaca sebagai yang terbesar.
 * Dengan urutan teks saja "...-9999" > "...-10000", sehingga nomor berikutnya bentrok.
 * Dipanggil di dalam transaksi; baris dikunci supaya dua dokumen tidak dapat nomor sama.
 */
class DocumentNumber
{
    public static function next(Builder $query, string $prefix, int $pad): string
    {
        $last = $query->where('number', 'like', "{$prefix}%")
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
    }
}
