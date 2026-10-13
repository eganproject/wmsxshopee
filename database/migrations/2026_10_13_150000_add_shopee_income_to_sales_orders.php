<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rincian pendapatan per pesanan dari Shopee (payment/get_escrow_detail):
        // harga, diskon, voucher, biaya admin/layanan/transaksi, ongkir & dana bersih.
        // Disimpan apa adanya (order_income); final setelah pesanan Selesai.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->json('shopee_income')->nullable()->after('shopee_synced_at');
            $table->decimal('shopee_escrow_amount', 15, 2)->nullable()->after('shopee_income');
            $table->boolean('shopee_income_final')->default(false)->after('shopee_escrow_amount');
            $table->timestamp('shopee_income_synced_at')->nullable()->after('shopee_income_final');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['shopee_income', 'shopee_escrow_amount', 'shopee_income_final', 'shopee_income_synced_at']);
        });
    }
};
