<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serah terima paket ke ekspedisi: satu sesi scan resi per kedatangan
        // kurir (pickup) atau per antaran ke cabang (drop off), dicetak sebagai manifest.
        Schema::create('shipment_handovers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipping_method', 10)->nullable();
            $table->string('status', 10)->default('open');
            $table->string('receiver_name', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Atur pengiriman: kurir menjemput paket di gudang (pickup) atau paket
        // diantar ke cabang/agen kurir (drop off). Untuk pesanan Shopee diatur
        // lewat API logistik, untuk pesanan manual cukup dicatat.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('shipping_method', 10)->nullable()->after('tracking_number');
            $table->timestamp('pickup_at')->nullable()->after('shipping_method');
            $table->string('pickup_slot', 100)->nullable()->after('pickup_at');
            $table->text('pickup_address')->nullable()->after('pickup_slot');
            $table->string('dropoff_point', 255)->nullable()->after('pickup_address');
            $table->timestamp('shipment_arranged_at')->nullable()->after('dropoff_point');
            $table->foreignId('shipment_arranged_by')->nullable()->after('shipment_arranged_at')->constrained('users')->nullOnDelete();
            $table->timestamp('shipping_label_printed_at')->nullable()->after('shipment_arranged_by');
            $table->timestamp('shopee_ship_by')->nullable()->after('shopee_synced_at');

            // QC scan saat kemas & serah terima ke ekspedisi.
            $table->foreignId('qc_checked_by')->nullable()->after('packed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('shipment_handover_id')->nullable()->after('shipped_at')->constrained()->nullOnDelete();
            $table->foreignId('shipped_by')->nullable()->after('shipment_handover_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qc_checked_by');
            $table->dropConstrainedForeignId('shipment_handover_id');
            $table->dropConstrainedForeignId('shipped_by');
            $table->dropConstrainedForeignId('shipment_arranged_by');
            $table->dropColumn(['shipping_method', 'pickup_at', 'pickup_slot', 'pickup_address', 'dropoff_point', 'shipment_arranged_at', 'shipping_label_printed_at', 'shopee_ship_by']);
        });

        Schema::dropIfExists('shipment_handovers');
    }
};
