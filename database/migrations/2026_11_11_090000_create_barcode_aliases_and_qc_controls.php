<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Barcode tambahan (alias) per varian, selain kolom barcode utama di product_variants.
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 50)->unique();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Kode yang di-scan tapi tidak dikenali sistem: satu baris terbuka per kode.
        Schema::create('barcode_scan_misses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('context', 20);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('scan_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 20)->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['code', 'resolved_at']);
            $table->index(['resolved_at', 'last_seen_at']);
        });

        // SKU yang tidak perlu di-scan saat QC kemas (hadiah, bonus, bahan kemasan).
        Schema::create('qc_exempt_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // QC ditahan: pesanan tetap Sedang Diambil, tapi tidak bisa di-QC sampai dilepas.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('qc_hold_reason')->nullable()->after('qc_skip_note');
            $table->timestamp('qc_held_at')->nullable()->after('qc_hold_reason');
            $table->foreignId('qc_held_by')->nullable()->after('qc_held_at')->constrained('users')->nullOnDelete();
        });

        // Substitusi SKU saat QC: barang pesanan diganti varian lain dengan alasan.
        Schema::create('qc_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('to_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->string('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });

        // Resi yang di-scan lagi padahal sudah lolos QC / sudah dikirim.
        Schema::create('scan_duplicate_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('context', 20);
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_status', 20)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_duplicate_attempts');
        Schema::dropIfExists('qc_substitutions');
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qc_held_by');
            $table->dropColumn(['qc_hold_reason', 'qc_held_at']);
        });
        Schema::dropIfExists('qc_exempt_variants');
        Schema::dropIfExists('barcode_scan_misses');
        Schema::dropIfExists('product_barcodes');
    }
};
