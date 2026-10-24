<?php

namespace App\Services;

use App\Models\LostItem;
use App\Models\WarehouseTransfer;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer antar gudang. Kirim: stok keluar dari bin gudang asal (mutasi
 * Kirim Antar Gudang) dan tercatat dalam perjalanan. Terima: stok masuk bin
 * gudang tujuan (mutasi Terima Antar Gudang); selisih kurang dicatat.
 * Batal: stok kembali ke bin asal.
 */
class WarehouseTransferService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array{from_warehouse_id: int, to_warehouse_id: int, transfer_date: string, reference?: ?string, notes?: ?string}  $header
     * @param  array<int, array{product_variant_id: int, from_location_id: ?int, to_location_id: ?int, qty: int, notes?: ?string}>  $items
     */
    public function send(array $header, array $items, ?int $userId): WarehouseTransfer
    {
        return DB::transaction(function () use ($header, $items, $userId) {
            $transfer = WarehouseTransfer::create([
                ...$header,
                'number' => $this->nextNumber(),
                'status' => 'sent',
                'created_by' => $userId,
            ]);
            $transfer->items()->createMany($items);

            $movement = $this->stock->record([
                'type' => 'transfer_out',
                'warehouse_id' => $transfer->from_warehouse_id,
                'movement_date' => $transfer->transfer_date->toDateString(),
                'reference' => $transfer->number,
                'notes' => 'Kirim ke gudang '.$transfer->toWarehouse()->withoutGlobalScope('warehouse_access')->value('code'),
                'created_by' => $userId,
            ], collect($items)->map(fn ($row) => [
                'product_variant_id' => $row['product_variant_id'],
                'from_location_id' => $row['from_location_id'],
                'qty' => $row['qty'],
                'notes' => $row['notes'] ?? null,
            ])->all());

            $transfer->update(['out_movement_id' => $movement->id]);

            return $transfer;
        });
    }

    /**
     * @param  array<int, array{received_qty: int, to_location_id: ?int}>  $rows  per item id
     */
    public function receive(WarehouseTransfer $transfer, array $rows, ?int $userId): void
    {
        DB::transaction(function () use ($transfer, $rows, $userId) {
            $transfer = $this->lock($transfer);
            $transfer->load('items.variant');
            $lines = [];

            foreach ($transfer->items as $item) {
                $row = $rows[$item->id] ?? [];
                $received = (int) ($row['received_qty'] ?? $item->qty);

                if ($received < 0 || $received > $item->qty) {
                    throw ValidationException::withMessages(["items.{$item->id}.received_qty" => "Jumlah diterima {$item->variant->sku} harus 0 sampai {$item->qty}."]);
                }

                $item->update([
                    'received_qty' => $received,
                    'to_location_id' => array_key_exists('to_location_id', $row) ? $row['to_location_id'] : $item->to_location_id,
                ]);

                // Kekurangan dicatat sebagai barang hilang: stoknya sudah keluar dari
                // gudang asal saat dikirim dan tidak masuk ke gudang tujuan.
                if ($received < $item->qty) {
                    LostItem::create([
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'from_warehouse_id' => $transfer->from_warehouse_id,
                        'product_variant_id' => $item->product_variant_id,
                        'source' => 'transfer',
                        'source_id' => $transfer->id,
                        'reference' => $transfer->number,
                        'lost_date' => now()->toDateString(),
                        'qty' => $item->qty - $received,
                        'notes' => "Dikirim {$item->qty}, diterima {$received}",
                        'created_by' => $userId,
                    ]);
                }

                if ($received > 0) {
                    $lines[] = [
                        'product_variant_id' => $item->product_variant_id,
                        'to_location_id' => $item->to_location_id,
                        'qty' => $received,
                        'notes' => $received < $item->qty ? 'Kurang '.($item->qty - $received).' dari yang dikirim' : null,
                    ];
                }
            }

            $movement = $lines ? $this->stock->record([
                'type' => 'transfer_in',
                'warehouse_id' => $transfer->to_warehouse_id,
                'movement_date' => now()->toDateString(),
                'reference' => $transfer->number,
                'notes' => 'Terima dari gudang '.$transfer->fromWarehouse()->withoutGlobalScope('warehouse_access')->value('code'),
                'created_by' => $userId,
            ], $lines) : null;

            $transfer->update([
                'status' => 'received',
                'in_movement_id' => $movement?->id,
                'received_by' => $userId,
                'received_at' => now(),
            ]);
        });
    }

    /** Batalkan transfer yang masih dalam perjalanan: stok kembali ke bin asal. */
    public function cancel(WarehouseTransfer $transfer, ?string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($transfer, $reason, $userId) {
            $transfer = $this->lock($transfer);
            $transfer->load('items');

            $movement = $this->stock->record([
                'type' => 'transfer_in',
                'warehouse_id' => $transfer->from_warehouse_id,
                'movement_date' => now()->toDateString(),
                'reference' => $transfer->number,
                'notes' => 'Batal transfer, stok kembali'.($reason ? ": {$reason}" : ''),
                'created_by' => $userId,
            ], $transfer->items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'to_location_id' => $item->from_location_id,
                'qty' => $item->qty,
            ])->all());

            $transfer->update([
                'status' => 'cancelled',
                'return_movement_id' => $movement->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });
    }

    private function lock(WarehouseTransfer $transfer): WarehouseTransfer
    {
        $locked = WarehouseTransfer::withoutGlobalScope('warehouse_access')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isSent()) {
            throw ValidationException::withMessages(['transfer' => "{$locked->number} berstatus {$locked->statusLabel()}, aksi ini tidak bisa dilakukan."]);
        }

        return $locked;
    }

    /** TAG-202610-0001, berurutan per bulan. */
    private function nextNumber(): string
    {
        $prefix = WarehouseTransfer::PREFIX.'-'.now()->format('Ym').'-';

        return DocumentNumber::next(WarehouseTransfer::withoutGlobalScope('warehouse_access'), $prefix, 4);
    }
}
