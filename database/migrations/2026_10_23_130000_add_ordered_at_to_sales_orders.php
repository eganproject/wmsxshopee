<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Waktu pembuatan pesanan di marketplace (tanggal + jam). Pesanan lama terisi saat sinkron berikutnya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('order_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('ordered_at');
        });
    }
};
