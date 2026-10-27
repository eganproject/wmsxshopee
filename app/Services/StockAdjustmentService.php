<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur dokumen Penyesuaian Stok: draft -> diajukan -> disetujui / ditolak.
 * Stok hanya berubah saat disetujui, lewat satu mutasi Penyesuaian di StockService.
 */
class StockAdjustmentService
{
    private const ATTEMPTS = 3;

    public function __construct(private StockService $stock) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array{product_variant_id: int, warehouse_location_id: ?int, qty: int, notes?: ?string}>  $items
     */
    public function draft(array $header, array $items, bool $submit = false): StockAdjustment
    {
        return DB::transaction(function () use ($header, $items, $submit) {
            $adjustment = StockAdjustment::create([
                ...$header,
                'number' => StockAdjustment::nextNumber(),
                'status' => $submit ? 'pending' : 'draft',
                'submitted_by' => $submit ? ($header['created_by'] ?? null) : null,
                'submitted_at' => $submit ? now() : null,
            ]);

            $adjustment->items()->createMany($items);

            return $adjustment;
        }, self::ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException bila dokumen bukan draft lagi
     */
    public function updateDraft(StockAdjustment $adjustment, array $header, array $items): void
    {
        DB::transaction(function () use ($adjustment, $header, $items) {
            $this->lock($adjustment, ['draft']);

            $adjustment->items()->delete();
            $adjustment->items()->createMany($items);
            $adjustment->update(collect($header)->except(['created_by'])->all());
        }, self::ATTEMPTS);
    }

    /** Ajukan draft untuk approval. */
    public function submit(StockAdjustment $adjustment, ?int $userId): void
    {
        DB::transaction(function () use ($adjustment, $userId) {
            $locked = $this->lock($adjustment, ['draft']);

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['adjustment' => 'Tambahkan minimal satu barang sebelum diajukan.']);
            }

            $locked->update(['status' => 'pending', 'submitted_by' => $userId, 'submitted_at' => now()]);
        }, self::ATTEMPTS);
    }

    /** Tarik kembali pengajuan ke draft supaya bisa diubah. */
    public function recall(StockAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $this->lock($adjustment, ['pending'])->update(['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null]);
        }, self::ATTEMPTS);
    }

    /**
     * Setujui: stok disesuaikan lewat satu mutasi Penyesuaian, saldo sebelum
     * dicatat per baris, dan dokumen terkunci.
     *
     * @throws ValidationException bila status tidak sesuai atau stok tidak cukup untuk dikurangi
     */
    public function approve(StockAdjustment $adjustment, ?int $userId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId) {
            $locked = $this->lock($adjustment, ['pending']);
            $items = $locked->items()->get();

            foreach ($items as $item) {
                $item->update(['qty_before' => $this->currentQty($locked->warehouse_id, $item->product_variant_id, $item->warehouse_location_id)]);
            }

            $movement = $this->stock->record([
                'type' => 'adjustment',
                'warehouse_id' => $locked->warehouse_id,
                'movement_date' => $locked->adjustment_date->toDateString(),
                'reference' => $locked->number,
                'notes' => trim("{$locked->reasonLabel()}. ".($locked->notes ?? '')),
                'created_by' => $userId,
            ], $items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'to_location_id' => $item->warehouse_location_id,
                'qty' => $item->qty,
                'notes' => $item->notes,
            ])->all());

            $locked->update([
                'status' => 'approved',
                'stock_movement_id' => $movement->id,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $locked;
        }, self::ATTEMPTS);
    }

    public function reject(StockAdjustment $adjustment, ?int $userId, string $reason): void
    {
        DB::transaction(function () use ($adjustment, $userId, $reason) {
            $this->lock($adjustment, ['pending'])->update([
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
        }, self::ATTEMPTS);
    }

    public function currentQty(int $warehouseId, int $variantId, ?int $locationId): int
    {
        return (int) Stock::withoutGlobalScope('warehouse_access')
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->when($locationId === null, fn ($q) => $q->whereNull('warehouse_location_id'), fn ($q) => $q->where('warehouse_location_id', $locationId))
            ->value('qty');
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws ValidationException
     */
    private function lock(StockAdjustment $adjustment, array $statuses): StockAdjustment
    {
        $locked = StockAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'adjustment' => "{$locked->number} sudah berstatus {$locked->statusLabel()}. Muat ulang halaman.",
            ]);
        }

        return $locked;
    }
}
