<?php

namespace App\Support;

/**
 * Stok selalu disimpan dalam pcs. Untuk varian yang punya isi per koli,
 * jumlah pcs bisa ditampilkan sebagai koli utuh + pcs sisa.
 */
class Cartons
{
    /** "12 koli + 5 pcs", "3 koli", atau null bila isi per koli kosong / belum sampai satu koli. */
    public static function label(int $qty, ?int $perCarton): ?string
    {
        if (! $perCarton || $perCarton < 1 || abs($qty) < $perCarton) {
            return null;
        }

        $sign = $qty < 0 ? '-' : '';
        $qty = abs($qty);
        $cartons = intdiv($qty, $perCarton);
        $loose = $qty % $perCarton;

        $parts = array_filter([
            $cartons ? number_format($cartons, 0, ',', '.').' koli' : null,
            $loose ? number_format($loose, 0, ',', '.').' pcs' : null,
        ]);

        return $sign.implode(' + ', $parts);
    }

    /** Bulatkan ke atas ke koli utuh (untuk saran transfer). */
    public static function roundUp(int $qty, ?int $perCarton): int
    {
        if (! $perCarton || $perCarton < 1 || $qty <= 0) {
            return max(0, $qty);
        }

        return (int) ceil($qty / $perCarton) * $perCarton;
    }
}
