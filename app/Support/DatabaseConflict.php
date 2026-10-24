<?php

namespace App\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Mengenali error database yang muncul karena beberapa pengguna mengubah data
 * yang sama bersamaan, supaya ditampilkan sebagai pesan yang bisa dimengerti.
 */
class DatabaseConflict
{
    use DetectsConcurrencyErrors;

    /** Pesan untuk pengguna, atau null bila bukan error bentrok/stok minus. */
    public static function message(Throwable $e): ?string
    {
        if (! $e instanceof QueryException) {
            return null;
        }

        if ((new self)->causedByConcurrencyError($e)) {
            return 'Data sedang diproses pengguna lain. Silakan coba lagi.';
        }

        if (self::violatesNonNegativeStock($e)) {
            return 'Stok tidak boleh minus. Muat ulang halaman lalu periksa saldo terbaru.';
        }

        return null;
    }

    /**
     * Baris yang akan dihapus masih dirujuk data lain (foreign key). Dipakai saat
     * menghapus master data yang ternyata sudah dipakai dokumen.
     */
    public static function stillReferenced(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        // MySQL/MariaDB 1451, PostgreSQL 23503, SQLite "FOREIGN KEY constraint failed".
        return ($e->errorInfo[1] ?? null) === 1451
            || ($e->errorInfo[0] ?? null) === '23503'
            || str_contains($e->getMessage(), 'FOREIGN KEY constraint failed');
    }

    private static function violatesNonNegativeStock(QueryException $e): bool
    {
        // MySQL 3819, MariaDB 4025, PostgreSQL 23514: CHECK constraint dilanggar.
        $code = $e->errorInfo[1] ?? null;

        return (in_array($code, [3819, 4025], true) || ($e->errorInfo[0] ?? null) === '23514')
            && str_contains($e->getMessage(), 'stocks_qty_non_negative');
    }
}
