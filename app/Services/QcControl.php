<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\QcSubstitution;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPick;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kendali QC kemas di luar scan biasa:
 *
 *   Tahan QC   : pesanan bermasalah (barang rusak, kurang, ragu) ditahan dengan alasan.
 *                Pesanan tetap Sedang Diambil, tapi tidak bisa di-QC sampai dilepas.
 *   Ganti SKU  : barang pesanan diganti varian lain (cth. warna habis, disetujui pembeli).
 *                Baris pesanan & bin picking ikut diganti, sehingga stok yang keluar saat
 *                serah terima adalah barang pengganti. Setiap penggantian dicatat beserta alasannya.
 */
class QcControl
{
    public function __construct(private readonly SalesOrderService $orders) {}

    /** @throws ValidationException */
    public function hold(SalesOrder $order, string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($order, $reason, $userId) {
            $locked = $this->lockPicking($order);

            if ($locked->isQcHeld()) {
                throw ValidationException::withMessages(['reason' => "{$locked->number} sudah ditahan: {$locked->qc_hold_reason}."]);
            }

            Activity::track($locked, fn () => $locked->update([
                'qc_hold_reason' => mb_substr(trim($reason), 0, 255),
                'qc_held_at' => now(),
                'qc_held_by' => $userId,
            ]));
        });
    }

    /** @throws ValidationException */
    public function release(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = $this->lockPicking($order);

            if (! $locked->isQcHeld()) {
                return;
            }

            Activity::track($locked, fn () => $locked->update(['qc_hold_reason' => null, 'qc_held_at' => null, 'qc_held_by' => null]));
        });
    }

    /**
     * Ganti sebagian / seluruh barang $fromVariantId di pesanan dengan $toVariantId.
     * Barang pengganti diambil dari bin dengan stok bebas (lokasi tetap SKU didahulukan).
     *
     * @throws ValidationException
     */
    public function substitute(SalesOrder $order, int $fromVariantId, int $toVariantId, int $qty, string $reason, ?int $userId): QcSubstitution
    {
        return DB::transaction(function () use ($order, $fromVariantId, $toVariantId, $qty, $reason, $userId) {
            $locked = $this->lockPicking($order);

            if ($locked->isQcHeld()) {
                throw ValidationException::withMessages(['to_code' => "QC {$locked->number} sedang ditahan. Lepas tahan dulu sebelum mengganti barang."]);
            }
            if ($fromVariantId === $toVariantId) {
                throw ValidationException::withMessages(['to_code' => 'Barang pengganti harus SKU yang berbeda.']);
            }

            // Kunci kedua varian (urut id) supaya tidak bentrok dengan picking pesanan lain.
            ProductVariant::whereIn('id', collect([$fromVariantId, $toVariantId])->sort()->values())->orderBy('id')->lockForUpdate()->get();
            $to = ProductVariant::findOrFail($toVariantId);

            if (! $to->is_active) {
                throw ValidationException::withMessages(['to_code' => "{$to->sku} nonaktif dan tidak bisa dipakai sebagai pengganti."]);
            }

            $items = $locked->items()->with('picks')->where('product_variant_id', $fromVariantId)->orderByDesc('id')->get();
            $ordered = (int) $items->sum('qty');
            if ($ordered === 0) {
                throw ValidationException::withMessages(['from_variant_id' => 'Barang yang diganti tidak ada di pesanan ini.']);
            }
            if ($qty < 1 || $qty > $ordered) {
                throw ValidationException::withMessages(['qty' => "Jumlah yang diganti harus 1 sampai {$ordered}."]);
            }

            $available = $this->orders->availability($locked->warehouse_id, [$toVariantId])[$toVariantId]['available'];
            if ($available < $qty) {
                throw ValidationException::withMessages(['to_code' => "Stok tersedia {$to->sku} di gudang ini hanya {$available}, butuh {$qty}."]);
            }

            $picks = $this->pickReplacement($locked, $to, $qty);

            // Kurangi baris barang asal (baris terakhir dulu) beserta bin picking-nya.
            $remaining = $qty;
            foreach ($items as $item) {
                if ($remaining === 0) {
                    break;
                }
                $take = min($remaining, $item->qty);
                $this->shrinkPicks($item, $take);
                $item->qty === $take ? $item->delete() : $item->update(['qty' => $item->qty - $take]);
                $remaining -= $take;
            }

            // Barang pengganti jadi baris baru (harga ikut barang asal per pcs).
            $source = $items->first();
            $line = $locked->items()->create([
                'product_variant_id' => $toVariantId,
                'qty' => $qty,
                'price' => $source->price,
                'notes' => mb_substr("Pengganti {$source->variant?->sku}: {$reason}", 0, 255),
            ]);
            $line->picks()->createMany($picks);

            $substitution = QcSubstitution::create([
                'sales_order_id' => $locked->id,
                'from_variant_id' => $fromVariantId,
                'to_variant_id' => $toVariantId,
                'qty' => $qty,
                'reason' => mb_substr(trim($reason), 0, 255),
                'user_id' => $userId,
            ]);

            $from = ProductVariant::find($fromVariantId);
            Activity::record('updated', 'sales', "Pesanan {$locked->number}: {$qty} {$from?->sku} diganti {$to->sku} saat QC", $locked,
                ['Barang' => "{$qty} × {$from?->sku}"], ['Barang' => "{$qty} × {$to->sku}", 'Alasan' => $reason]);

            // Cadangan stok berubah: stok jual di marketplace ikut diperbarui.
            app(MarketplaceStockPush::class)->queue();

            return $substitution;
        });
    }

    /**
     * Bin untuk barang pengganti: lokasi tetap SKU dulu, lalu bin dengan stok bebas terbanyak.
     *
     * @return list<array{warehouse_location_id: ?int, qty: int}>
     *
     * @throws ValidationException
     */
    private function pickReplacement(SalesOrder $order, ProductVariant $to, int $qty): array
    {
        $priorities = app(FixedBins::class)->priorities($order->warehouse_id, [$to->id])[$to->id] ?? [];
        $slots = $this->orders->freeByLocation($order->warehouse_id, [$to->id])->get($to->id, collect())
            ->filter(fn ($slot) => $slot['qty'] > 0)
            ->sortBy([
                fn ($a, $b) => ($priorities[$a['location_id']] ?? PHP_INT_MAX) <=> ($priorities[$b['location_id']] ?? PHP_INT_MAX),
                fn ($a, $b) => $b['qty'] <=> $a['qty'],
            ]);

        $rows = [];
        $needed = $qty;
        foreach ($slots as $slot) {
            $take = min($needed, $slot['qty']);
            $rows[] = ['warehouse_location_id' => $slot['location_id'], 'qty' => $take];
            $needed -= $take;
            if ($needed === 0) {
                break;
            }
        }

        if ($needed > 0) {
            throw ValidationException::withMessages(['to_code' => "Stok bebas {$to->sku} di bin gudang ini kurang {$needed} pcs (sudah dipakai picking pesanan lain)."]);
        }

        return $rows;
    }

    /** Kurangi bin picking satu baris sebanyak $qty, mulai dari bin terakhir. */
    private function shrinkPicks(SalesOrderItem $item, int $qty): void
    {
        foreach ($item->picks->sortByDesc('id') as $pick) {
            if ($qty === 0) {
                break;
            }
            /** @var SalesOrderPick $pick */
            $take = min($qty, $pick->qty);
            $pick->qty === $take ? $pick->delete() : $pick->update(['qty' => $pick->qty - $take]);
            $qty -= $take;
        }
    }

    /** @throws ValidationException */
    private function lockPicking(SalesOrder $order): SalesOrder
    {
        $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'picking') {
            throw ValidationException::withMessages(['order' => "{$locked->number} berstatus {$locked->statusLabel()}. Tahan QC & ganti barang hanya untuk pesanan Sedang Diambil."]);
        }

        return $locked;
    }
}
