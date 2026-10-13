<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_arranged_by');
            $table->dropColumn(['shipping_method', 'pickup_at', 'pickup_slot', 'pickup_address', 'dropoff_point', 'shipment_arranged_at', 'shipping_label_printed_at', 'shopee_ship_by']);
        });
    }
};
