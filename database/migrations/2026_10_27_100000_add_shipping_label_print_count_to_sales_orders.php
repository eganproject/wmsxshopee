<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Berapa kali label resi dicetak dari Optima, supaya cetak ulang mudah terlihat.
            $table->unsignedSmallInteger('shipping_label_print_count')->default(0)->after('shipping_label_printed_at');
            $table->index('shipping_label_printed_at');
        });

        // Pesanan yang sudah pernah dicetak sebelum kolom ini ada dihitung sekali.
        DB::table('sales_orders')->whereNotNull('shipping_label_printed_at')->update(['shipping_label_print_count' => 1]);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['shipping_label_printed_at']);
            $table->dropColumn('shipping_label_print_count');
        });
    }
};
