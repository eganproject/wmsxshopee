<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Pesanan yang dikirim otomatis mengikuti marketplace (terlewat QC & serah terima)
            // dicek supervisor setelahnya: siapa, kapan dan catatannya.
            $table->timestamp('qc_skip_checked_at')->nullable()->after('shipped_automatically');
            $table->foreignId('qc_skip_checked_by')->nullable()->after('qc_skip_checked_at')->constrained('users')->nullOnDelete();
            $table->string('qc_skip_note', 255)->nullable()->after('qc_skip_checked_by');
            $table->index(['shipped_automatically', 'qc_skip_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['shipped_automatically', 'qc_skip_checked_at']);
            $table->dropConstrainedForeignId('qc_skip_checked_by');
            $table->dropColumn(['qc_skip_checked_at', 'qc_skip_note']);
        });
    }
};
