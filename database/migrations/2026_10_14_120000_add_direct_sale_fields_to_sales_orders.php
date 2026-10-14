<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Penjualan langsung (WhatsApp, toko offline): dikirim kurir atau diambil pembeli di gudang.
            $table->string('delivery_type', 20)->default('courier')->after('shipping_address');
            $table->decimal('discount', 15, 2)->default(0)->after('shipping_fee');
            $table->string('payment_method', 30)->nullable()->after('discount');
            $table->string('payment_status', 20)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_type', 'discount', 'payment_method', 'payment_status']);
        });
    }
};
