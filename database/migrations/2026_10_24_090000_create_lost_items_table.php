<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan barang hilang: kekurangan saat terima transfer antar gudang dan barang
 * yang tidak ada di paket retur. Stoknya sudah tidak ada di saldo mana pun, jadi
 * tabel ini hanya jejak untuk laporan, tidak mengubah stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 50);
            $table->date('lost_date');
            $table->unsignedInteger('qty');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['warehouse_id', 'lost_date']);
            $table->index(['source', 'source_id']);
        });

        $now = now();

        // Transfer yang sudah diterima kurang sebelum fitur ini ada.
        DB::table('warehouse_transfer_items')
            ->join('warehouse_transfers', 'warehouse_transfers.id', '=', 'warehouse_transfer_items.warehouse_transfer_id')
            ->where('warehouse_transfers.status', 'received')
            ->whereColumn('warehouse_transfer_items.received_qty', '<', 'warehouse_transfer_items.qty')
            ->orderBy('warehouse_transfer_items.id')
            ->select('warehouse_transfer_items.*', 'warehouse_transfers.number', 'warehouse_transfers.to_warehouse_id',
                'warehouse_transfers.from_warehouse_id', 'warehouse_transfers.received_at', 'warehouse_transfers.received_by')
            ->get()
            ->each(fn ($row) => DB::table('lost_items')->insert([
                'warehouse_id' => $row->to_warehouse_id,
                'from_warehouse_id' => $row->from_warehouse_id,
                'product_variant_id' => $row->product_variant_id,
                'source' => 'transfer',
                'source_id' => $row->warehouse_transfer_id,
                'reference' => $row->number,
                'lost_date' => substr((string) ($row->received_at ?? $now), 0, 10),
                'qty' => $row->qty - $row->received_qty,
                'notes' => "Dikirim {$row->qty}, diterima {$row->received_qty}",
                'created_by' => $row->received_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        // Retur yang sudah diterima dengan barang hilang.
        DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->where('sales_returns.status', 'received')
            ->where('sales_return_items.qty_missing', '>', 0)
            ->orderBy('sales_return_items.id')
            ->select('sales_return_items.*', 'sales_returns.number', 'sales_returns.warehouse_id', 'sales_returns.return_date', 'sales_returns.received_by')
            ->get()
            ->each(fn ($row) => DB::table('lost_items')->insert([
                'warehouse_id' => $row->warehouse_id,
                'from_warehouse_id' => null,
                'product_variant_id' => $row->product_variant_id,
                'source' => 'return',
                'source_id' => $row->sales_return_id,
                'reference' => $row->number,
                'lost_date' => substr((string) $row->return_date, 0, 10),
                'qty' => $row->qty_missing,
                'notes' => 'Tidak ada di paket retur',
                'created_by' => $row->received_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_items');
    }
};
