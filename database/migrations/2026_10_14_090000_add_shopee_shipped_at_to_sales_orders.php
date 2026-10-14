<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Saat Shopee pertama kali melaporkan paket sudah di tangan kurir
            // (SHIPPED / TO_CONFIRM_RECEIVE / COMPLETED): tanda resi berhasil dikirim.
            $table->timestamp('shopee_shipped_at')->nullable()->after('shopee_ship_by');
            // Pesanan dikirim otomatis dari status Shopee, bukan lewat scan serah terima.
            $table->boolean('shipped_automatically')->default(false)->after('shipped_by');
            $table->index(['shopee_order_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['shopee_order_status', 'status']);
            $table->dropColumn(['shopee_shipped_at', 'shipped_automatically']);
        });
    }
};
