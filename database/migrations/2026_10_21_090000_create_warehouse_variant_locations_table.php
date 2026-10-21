<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi tetap SKU bisa lebih dari satu bin per gudang, berurutan prioritas:
 * bin utama (priority 1) lalu bin cadangan. Kapasitas opsional dipakai untuk
 * membagi saran penerimaan/transfer; kosong = tanpa batas.
 * warehouse_variant_settings.default_location_id tetap diisi bin utama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouse_variant_locations')) {
            Schema::create('warehouse_variant_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_location_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('priority')->default(1);
                $table->unsignedInteger('capacity')->nullable();
                $table->timestamps();

                $table->unique(['warehouse_id', 'product_variant_id', 'warehouse_location_id'], 'wvl_unique');
                $table->index(['warehouse_id', 'product_variant_id', 'priority'], 'wvl_priority');
            });
        }

        // Lokasi tetap lama jadi bin utama.
        $now = now();
        DB::table('warehouse_variant_settings')->whereNotNull('default_location_id')->orderBy('id')
            ->each(function ($setting) use ($now) {
                DB::table('warehouse_variant_locations')->insertOrIgnore([
                    'warehouse_id' => $setting->warehouse_id,
                    'product_variant_id' => $setting->product_variant_id,
                    'warehouse_location_id' => $setting->default_location_id,
                    'priority' => 1,
                    'capacity' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_variant_locations');
    }
};
