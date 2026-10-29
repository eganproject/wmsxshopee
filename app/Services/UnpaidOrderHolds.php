<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\MarketplaceStockHold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cadangan sementara untuk pesanan marketplace yang belum dibayar.
 *
 * Shopee & TikTok sudah mengurangi stok toko saat pembeli checkout, tapi Optima
 * baru mencadangkan stok setelah pesanan dibayar. Selama belum dibayar, stok yang
 * dikirim ke semua toko dikurangi qty pesanan ini, supaya barang yang sama tidak
 * terjual dua kali di toko lain (atau tertimpa di toko yang sama).
 *
 * Cadangan ini TIDAK menahan konfirmasi pesanan yang sudah dibayar: stok gudang
 * tetap untuk pesanan yang sudah pasti. Cadangan lepas begitu pesanan dibayar
 * (berganti jadi pesanan Optima), dibatalkan, atau lewat batas waktunya.
 */
class UnpaidOrderHolds
{
    /** Status marketplace yang ditahan: belum dibayar / masa batal TikTok. */
    public const STATUSES = [
        'shopee' => ['UNPAID'],
        'tiktok' => ['UNPAID', 'ON_HOLD'],
    ];

    public static function applies(string $platform, string $status): bool
    {
        return in_array($status, self::STATUSES[$platform] ?? [], true);
    }

    /**
     * Ganti cadangan satu pesanan dengan barisnya sekarang.
     *
     * @param  list<array{product_variant_id: int, qty: int}>  $items
     * @param  list<array{bundle_id: int, qty: int}>  $bundles  dipecah ke komponennya
     */
    public function hold(string $platform, int $shopId, string $orderRef, string $status, int $warehouseId, array $items, array $bundles, ?Carbon $orderedAt = null): void
    {
        $qty = [];
        foreach ($items as $item) {
            $qty[$item['product_variant_id']] = ($qty[$item['product_variant_id']] ?? 0) + (int) $item['qty'];
        }

        $masters = Bundle::with('items')->whereIn('id', collect($bundles)->pluck('bundle_id'))->get()->keyBy('id');
        foreach ($bundles as $line) {
            foreach ($masters->get($line['bundle_id'])?->items ?? [] as $component) {
                $qty[$component->product_variant_id] = ($qty[$component->product_variant_id] ?? 0) + (int) $line['qty'] * (int) $component->qty;
            }
        }

        $qty = array_filter($qty, fn ($value) => $value > 0);
        $expires = ($orderedAt && $orderedAt->isPast() ? $orderedAt->copy() : now())->addHours((int) config('marketplace.unpaid_hold_hours', 72));

        $before = $this->current($platform, $shopId, $orderRef);

        DB::transaction(function () use ($platform, $shopId, $orderRef, $status, $warehouseId, $qty, $expires) {
            MarketplaceStockHold::where(['platform' => $platform, 'shop_id' => $shopId, 'order_ref' => $orderRef])->delete();

            foreach ($qty as $variantId => $value) {
                MarketplaceStockHold::create([
                    'platform' => $platform,
                    'shop_id' => $shopId,
                    'order_ref' => $orderRef,
                    'order_status' => $status,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variantId,
                    'qty' => $value,
                    'expires_at' => $expires,
                ]);
            }
        });

        if ($before !== $this->current($platform, $shopId, $orderRef)) {
            app(MarketplaceStockPush::class)->queue();
        }
    }

    /** Lepas cadangan pesanan (dibayar, dibatalkan, atau tidak lagi belum bayar). */
    public function release(string $platform, int $shopId, string $orderRef): void
    {
        $deleted = MarketplaceStockHold::where(['platform' => $platform, 'shop_id' => $shopId, 'order_ref' => $orderRef])->delete();

        if ($deleted) {
            app(MarketplaceStockPush::class)->queue();
        }
    }

    /**
     * Qty yang sedang ditahan per varian di satu gudang.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, int>
     */
    public function held(int $warehouseId, array $variantIds): Collection
    {
        if (! $variantIds) {
            return collect();
        }

        return MarketplaceStockHold::query()
            ->active()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(qty) as qty')
            ->pluck('qty', 'product_variant_id')
            ->map(fn ($qty) => (int) $qty);
    }

    /** @return array<int, int> */
    private function current(string $platform, int $shopId, string $orderRef): array
    {
        return MarketplaceStockHold::where(['platform' => $platform, 'shop_id' => $shopId, 'order_ref' => $orderRef])
            ->orderBy('product_variant_id')
            ->pluck('qty', 'product_variant_id')
            ->all();
    }
}
