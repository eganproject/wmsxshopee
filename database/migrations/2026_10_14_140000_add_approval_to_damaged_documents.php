<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dokumen barang rusak kini lewat draft dulu (bisa diubah/dihapus) dan baru
     * mengubah stok setelah disetujui. Dokumen lama sudah memotong stok, jadi
     * ditandai disetujui oleh pembuatnya.
     */
    public function up(): void
    {
        Schema::table('damaged_documents', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('type');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->index(['status', 'document_date']);
        });

        DB::table('damaged_documents')->update([
            'status' => 'approved',
            'approved_by' => DB::raw('created_by'),
            'approved_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('damaged_documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'document_date']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at']);
        });
    }
};
