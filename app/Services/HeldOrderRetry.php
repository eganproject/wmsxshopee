<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan marketplace yang tertahan sebagai draft karena stok kurang dikonfirmasi
 * ulang otomatis begitu stok bertambah (penerimaan, transfer, penyesuaian, pesanan
 * lain batal) dan tiap menit dari penjadwal. Pesanan paling lama dulu, supaya
 * stok yang baru masuk dipakai pesanan yang paling dulu menunggu.
 */
class HeldOrderRetry
{
    /** Batas pesanan per putaran, supaya satu putaran tetap singkat. */
    public const BATCH = 300;

    private bool $queued = false;

    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly WarehouseSupply $supply,
    ) {}

    /** Jadwalkan percobaan ulang di akhir request / perintah (dipanggil dari event model). */
    public function queue(): void
    {
        if ($this->queued) {
            return;
        }

        $this->queued = true;

        app()->terminating(function () {
            try {
                $this->retryAll();
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $this->queued = false;
            }
        });
    }

    /**
     * Coba konfirmasi semua pesanan yang tertahan stok.
     *
     * @return int pesanan yang berhasil dikonfirmasi
     */
    public function retryAll(): int
    {
        // Satu putaran pada satu waktu; putaran berikutnya (jadwal tiap menit) menyusul.
        return (int) Cache::lock('held-order-retry', 120)->get(fn () => $this->run());
    }

    private function run(): int
    {
        $this->queued = true; // konfirmasi di bawah tidak perlu menjadwalkan putaran baru
        $confirmed = 0;

        try {
            $held = SalesOrder::withoutGlobalScope('warehouse_access')->stockHeld()
                ->orderByRaw('COALESCE(ordered_at, created_at)')->orderBy('id')
                ->limit(self::BATCH)->get();

            foreach ($held as $order) {
                $column = $order->shopee_shop_id ? 'shopee_sync_error' : 'tiktok_sync_error';

                try {
                    $this->orders->confirm($order);
                    $order->refresh()->update([$column => null]);
                    $confirmed++;
                } catch (ValidationException $e) {
                    // Alasan bisa berubah (cth. sisa stok berkurang); simpan hanya bila beda.
                    $message = mb_substr(SalesOrder::STOCK_HELD_PREFIX.implode(' ', $e->validator->errors()->all()), 0, 500);
                    $order->refresh();
                    if ($order->status === 'draft' && $order->{$column} !== $message) {
                        $order->update([$column => $message]);
                    }
                }
            }
        } finally {
            $this->queued = false;
        }

        return $confirmed;
    }

    /**
     * Kekurangan per SKU untuk satu pesanan tertahan, beserta gudang lain yang
     * masih punya stok tersedia (sumber transfer).
     *
     * @return Collection<int, object{variant: ProductVariant, needed: int, sellable: int, short: int, sources: Collection<int, object{warehouse: Warehouse, available: int}>}>
     */
    public function shortages(SalesOrder $order): Collection
    {
        $order->loadMissing('items.variant');
        $variantIds = $order->items->pluck('product_variant_id')->unique()->values()->all();
        $sellable = $this->supply->sellable($order->warehouse_id, $variantIds, $order->id);
        $short = $order->items->groupBy('product_variant_id')
            ->map(function ($rows, $variantId) use ($sellable) {
                $needed = (int) $rows->sum('qty');
                $have = (int) ($sellable[$variantId]['sellable'] ?? 0);

                return (object) ['variant' => $rows->first()->variant, 'needed' => $needed, 'sellable' => $have, 'short' => max(0, $needed - $have), 'sources' => collect()];
            })
            ->filter(fn ($row) => $row->short > 0);

        if ($short->isEmpty()) {
            return $short->values();
        }

        $ids = $short->keys()->all();
        foreach (Warehouse::where('is_active', true)->whereKeyNot($order->warehouse_id)->orderBy('code')->get() as $warehouse) {
            foreach ($this->orders->availability($warehouse->id, $ids) as $variantId => $row) {
                if ($row['available'] > 0) {
                    $short[$variantId]->sources->push((object) ['warehouse' => $warehouse, 'available' => $row['available']]);
                }
            }
        }

        return $short->values();
    }

    /**
     * Gudang sumber terbaik untuk menutup kekurangan pesanan: gudang cadangannya
     * bila punya stok, kalau tidak gudang yang bisa menutup kekurangan terbanyak.
     *
     * @param  Collection<int, object>  $shortages  hasil shortages()
     */
    public function bestSource(SalesOrder $order, Collection $shortages): ?Warehouse
    {
        $cover = [];
        $warehouses = [];

        foreach ($shortages as $row) {
            foreach ($row->sources as $source) {
                $warehouses[$source->warehouse->id] = $source->warehouse;
                $cover[$source->warehouse->id] = ($cover[$source->warehouse->id] ?? 0) + min($row->short, $source->available);
            }
        }

        if (! $cover) {
            return null;
        }

        $supplyId = $order->warehouse?->supply_warehouse_id;
        arsort($cover);

        return $warehouses[$supplyId !== null && isset($cover[$supplyId]) ? $supplyId : array_key_first($cover)];
    }
}
