<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aplikasi Android: foto paket saat QC kemas dan tanda tangan kurir di manifest serah terima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('packing_photo_path')->nullable()->after('qc_checked_by');
        });

        Schema::table('shipment_handovers', function (Blueprint $table) {
            $table->string('receiver_signature_path')->nullable()->after('receiver_name');
            $table->timestamp('signed_at')->nullable()->after('receiver_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('packing_photo_path');
        });

        Schema::table('shipment_handovers', function (Blueprint $table) {
            $table->dropColumn(['receiver_signature_path', 'signed_at']);
        });
    }
};
