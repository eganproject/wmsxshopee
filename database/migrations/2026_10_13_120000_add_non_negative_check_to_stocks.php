<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Saldo stok tidak boleh minus, dijaga juga di level database (MySQL 8.0.16+,
     * MariaDB 10.2+, PostgreSQL). SQLite dilewati: tidak bisa menambah CHECK ke
     * tabel yang sudah ada; di sana cukup pengaman di model Stock.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        if (DB::table('stocks')->where('qty', '<', 0)->exists()) {
            // Jangan gagalkan migrate: data lama yang minus dibereskan dulu lewat
            // penyesuaian stok, lalu jalankan `php artisan stock:check`.
            Log::warning('CHECK stocks_qty_non_negative tidak dipasang: masih ada saldo stok minus.');

            return;
        }

        try {
            DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_qty_non_negative CHECK (qty >= 0)');
        } catch (QueryException $e) {
            // Versi database lama yang tidak mengenal CHECK: model Stock tetap menjaga.
            Log::warning('CHECK stocks_qty_non_negative tidak dipasang: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        rescue(fn () => DB::statement('ALTER TABLE stocks DROP CONSTRAINT stocks_qty_non_negative'), report: false);
    }
};
