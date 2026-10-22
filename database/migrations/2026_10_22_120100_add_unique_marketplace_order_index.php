<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu nomor pesanan marketplace hanya boleh jadi satu Pesanan per toko, walau
 * webhook dan sinkron terjadwal memproses pesanan yang sama bersamaan.
 * Bila data lama sudah terlanjur dobel, index dilewati (dicatat di log) supaya
 * migrasi tidak gagal; kunci per pesanan di kode tetap mencegah dobel baru.
 */
return new class extends Migration
{
    private const INDEXES = [
        'shopee_shop_id' => 'sales_orders_shopee_order_unique',
        'tiktok_shop_id' => 'sales_orders_tiktok_order_unique',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $column => $name) {
            $duplicates = DB::table('sales_orders')
                ->whereNotNull($column)->whereNotNull('external_number')
                ->groupBy($column, 'external_number')
                ->havingRaw('COUNT(*) > 1')
                ->select($column, 'external_number')
                ->get();

            if ($duplicates->isNotEmpty()) {
                logger()->warning("Index unik {$name} dilewati: ada pesanan marketplace dobel.", ['contoh' => $duplicates->take(10)->all()]);

                continue;
            }

            Schema::table('sales_orders', fn (Blueprint $table) => $table->unique([$column, 'external_number'], $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name) {
            if (Schema::hasIndex('sales_orders', $name)) {
                Schema::table('sales_orders', fn (Blueprint $table) => $table->dropUnique($name));
            }
        }
    }
};
