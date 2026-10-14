<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saldo barang rusak per varian per gudang, terpisah dari stok layak jual
        // (tabel stocks) sehingga tidak ikut terjual, dicadangkan atau disinkron ke Shopee.
        // Barang rusak tidak disimpan per lokasi bin: cukup satu saldo per gudang.
        Schema::create('damaged_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->integer('qty')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });

        // Dokumen barang rusak: pindah stok baik ke rusak, retur ke supplier / pihak lain,
        // pemusnahan, perbaikan (kembali ke stok baik) dan rakit / campur (barang rusak +
        // barang lain jadi barang baru). Tidak bisa diubah setelah disimpan.
        Schema::create('damaged_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('type', 20);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('document_date');
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            // Penerima selain supplier (retur ke pihak lain), cth. distributor / service center.
            $table->string('recipient', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            // Mutasi stok baik yang ikut tercatat (pindah ke rusak, hasil perbaikan, rakit).
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'document_date']);
        });

        Schema::create('damaged_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damaged_document_id')->constrained()->cascadeOnDelete();
            // input = barang yang dipakai / dikeluarkan, output = hasil rakit (stok baik).
            $table->string('role', 10)->default('input');
            // Asal barang input: damaged (stok rusak) atau good (stok baik, untuk bahan rakit).
            $table->string('source', 10)->default('damaged');
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Kartu stok rusak: satu baris per perubahan saldo. Sumbernya penerimaan barang,
        // retur penjualan atau dokumen barang rusak (morph source).
        Schema::create('damaged_stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->nullableMorphs('source');
            $table->string('reference', 100)->nullable();
            $table->date('movement_date');
            $table->unsignedInteger('qty_in')->default(0);
            $table->unsignedInteger('qty_out')->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_variant_id', 'movement_date', 'id']);
            $table->index(['warehouse_id', 'movement_date']);
        });

        $this->backfill();
        $this->addCheck();
    }

    /**
     * Barang rusak yang sudah tercatat di penerimaan & retur yang diterima sebelum
     * fitur ini ada dijadikan saldo awal, supaya tidak hilang dari catatan. Yang
     * secara fisik sudah tidak ada bisa dikeluarkan lewat Retur ke Supplier / Pemusnahan.
     */
    private function backfill(): void
    {
        $now = now();
        $sources = [
            ['receipt', 'goods_receipts', 'goods_receipt_items', 'goods_receipt_id', 'receipt_date', 'App\\Models\\GoodsReceipt'],
            ['return', 'sales_returns', 'sales_return_items', 'sales_return_id', 'return_date', 'App\\Models\\SalesReturn'],
        ];

        foreach ($sources as [$type, $header, $lines, $foreign, $date, $class]) {
            DB::table($lines)
                ->join($header, "{$header}.id", '=', "{$lines}.{$foreign}")
                ->where("{$header}.status", 'received')
                ->where("{$lines}.qty_damaged", '>', 0)
                ->orderBy("{$lines}.id")
                ->select(["{$lines}.product_variant_id", "{$lines}.qty_damaged", "{$header}.id as header_id", "{$header}.number",
                    "{$header}.warehouse_id", "{$header}.{$date} as doc_date", "{$header}.received_by"])
                ->chunk(500, function ($rows) use ($type, $class, $now) {
                    foreach ($rows as $row) {
                        DB::table('damaged_stock_ledgers')->insert([
                            'product_variant_id' => $row->product_variant_id,
                            'warehouse_id' => $row->warehouse_id,
                            'type' => $type,
                            'source_type' => $class,
                            'source_id' => $row->header_id,
                            'reference' => $row->number,
                            'movement_date' => $row->doc_date,
                            'qty_in' => $row->qty_damaged,
                            'qty_out' => 0,
                            'notes' => 'Saldo awal dari dokumen yang diterima sebelum ada stok rusak',
                            'created_by' => $row->received_by,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });
        }

        DB::table('damaged_stock_ledgers')
            ->groupBy('product_variant_id', 'warehouse_id')
            ->selectRaw('product_variant_id, warehouse_id, SUM(qty_in) as qty')
            ->get()
            ->each(fn ($row) => DB::table('damaged_stocks')->insert([
                'product_variant_id' => $row->product_variant_id,
                'warehouse_id' => $row->warehouse_id,
                'qty' => (int) $row->qty,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    /** Sama seperti stocks: saldo rusak tidak boleh minus, dijaga juga di database. */
    private function addCheck(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement('ALTER TABLE damaged_stocks ADD CONSTRAINT damaged_stocks_qty_non_negative CHECK (qty >= 0)');
        } catch (QueryException $e) {
            Log::warning('CHECK damaged_stocks_qty_non_negative tidak dipasang: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('damaged_stock_ledgers');
        Schema::dropIfExists('damaged_document_items');
        Schema::dropIfExists('damaged_documents');
        Schema::dropIfExists('damaged_stocks');
    }
};
