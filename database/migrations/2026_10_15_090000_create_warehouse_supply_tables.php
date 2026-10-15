<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gudang besar (stok koli) sebagai cadangan gudang kecil (fulfilment):
 * - warehouses.supply_warehouse_id: gudang cadangan yang stoknya ikut dijual.
 * - product_variants.pcs_per_carton: isi per koli, stok tetap disimpan pcs.
 * - warehouse_variant_settings: lokasi tetap SKU + min/max per gudang.
 * - warehouse_transfers: kirim/terima stok antar gudang (status dalam perjalanan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga hasTable/hasColumn supaya bisa dijalankan ulang di atas migrasi
        // yang sempat berhenti di tengah (MySQL tidak me-rollback DDL).
        if (! Schema::hasColumn('warehouses', 'supply_warehouse_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->foreignId('supply_warehouse_id')->nullable()->after('pic_name')->constrained('warehouses')->nullOnDelete();
                $table->unsignedTinyInteger('supply_stock_percent')->default(100)->after('supply_warehouse_id');
            });
        }

        if (! Schema::hasColumn('product_variants', 'pcs_per_carton')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->unsignedInteger('pcs_per_carton')->nullable()->after('min_stock');
            });
        }

        // Tabel ini mungkin sudah ada tanpa unique index dari percobaan sebelumnya.
        Schema::dropIfExists('warehouse_variant_settings');

        Schema::create('warehouse_variant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->unsignedInteger('min_qty')->nullable();
            $table->unsignedInteger('max_qty')->nullable();
            $table->timestamps();

            // Nama index dipendekkan: nama otomatis melebihi batas 64 karakter MySQL.
            $table->unique(['warehouse_id', 'product_variant_id'], 'wvs_warehouse_variant_unique');
        });

        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('sent');
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('out_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('in_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('return_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'to_warehouse_id']);
        });

        Schema::create('warehouse_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('received_qty')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('warehouse_variant_settings');

        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('pcs_per_carton'));

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_warehouse_id');
            $table->dropColumn('supply_stock_percent');
        });
    }
};
