<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\ShipmentHandover;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Proses berbasis scan di gudang:
 *
 *   QC kemas     : scan resi / no. SO -> scan barcode atau SKU tiap barang. Bila semua
 *                  jumlah cocok, pesanan Sudah Diambil -> Sudah Dikemas.
 *   Serah terima : scan resi / no. pesanan saat diserahkan ke kurir / cabang drop off.
 *                  Tiap scan langsung mengirim pesanan (stok keluar) dan otomatis masuk
 *                  manifest harian gudang & kurirnya.
 */
class FulfillmentScan
{
    public function __construct(private readonly SalesOrderService $orders) {}

    /**
     * Barang yang harus di-scan per varian, plus kode paket yang boleh di-scan
     * sekaligus (satu scan paket = semua isinya).
     *
     * @return array{lines: list<array<string, mixed>>, bundles: array<string, array<int, int>>}
     */
    public function expected(SalesOrder $order): array
    {
        $order->loadMissing(['items.variant.product', 'bundles.bundle.items']);

        $lines = $order->items->groupBy('product_variant_id')->map(function (Collection $rows) {
            $variant = $rows->first()->variant;

            return [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => trim($variant->product->name.($variant->name ? ' / '.$variant->name : '')),
                'qty' => (int) $rows->sum('qty'),
            ];
        })->values()->all();

        $bundles = [];
        foreach ($order->bundles as $line) {
            $contents = $line->bundle->items->mapWithKeys(fn ($item) => [$item->product_variant_id => (int) $item->qty])->all();
            foreach (array_filter([$line->bundle->sku, $line->bundle->barcode]) as $code) {
                $bundles[mb_strtolower($code)] = $contents;
            }
        }

        return ['lines' => $lines, 'bundles' => $bundles];
    }

    /**
     * Selesaikan QC: jumlah hasil scan per varian harus sama persis dengan pesanan.
     *
     * @param  array<int|string, int|string>  $scanned  product_variant_id => qty
     *
     * @throws ValidationException
     */
    public function completeQc(SalesOrder $order, array $scanned, ?int $userId): void
    {
        $expected = collect($this->expected($order)['lines'])->keyBy('variant_id');
        $problems = [];

        foreach ($expected as $variantId => $line) {
            $qty = (int) ($scanned[$variantId] ?? 0);
            if ($qty !== $line['qty']) {
                $problems[] = "{$line['sku']}: di-scan {$qty} dari {$line['qty']}";
            }
        }

        foreach (array_diff(array_map('intval', array_keys(array_filter($scanned))), $expected->keys()->all()) as $variantId) {
            $problems[] = "Ada barang di-scan yang tidak ada di pesanan (varian #{$variantId})";
        }

        if ($problems) {
            throw ValidationException::withMessages(['scan' => 'QC belum cocok. '.implode('; ', $problems).'.']);
        }

        DB::transaction(function () use ($order, $userId) {
            $this->orders->pack($order);
            $order->refresh()->update(['qc_checked_by' => $userId]);
        });
    }

    /**
     * Scan resi / no. pesanan di serah terima: kirim pesanan & catat di manifest hari ini.
     *
     * @throws ValidationException
     */
    public function handover(string $code, ?int $userId): SalesOrder
    {
        $order = SalesOrder::findByScan($code);

        if (! $order) {
            throw ValidationException::withMessages(['code' => "Resi / pesanan \"{$code}\" tidak ditemukan."]);
        }

        if ($order->hasStatus('shipped')) {
            throw ValidationException::withMessages(['code' => "{$order->number} sudah dikirim".($order->handover ? " lewat {$order->handover->number}" : '').'.']);
        }

        if (! $order->hasStatus('packed')) {
            throw ValidationException::withMessages(['code' => "{$order->number} berstatus {$order->statusLabel()}; paket harus lolos QC (Sudah Dikemas) sebelum diserahkan."]);
        }

        if ($order->isSelfPickup()) {
            throw ValidationException::withMessages(['code' => "{$order->number} diambil pembeli, bukan lewat kurir. Serahkan dari halaman pesanannya."]);
        }

        if ($order->isShopee()) {
            if ($order->shopee_order_status === 'IN_CANCEL' || $order->shopee_order_status === 'CANCELLED') {
                throw ValidationException::withMessages(['code' => "{$order->number}: pembeli mengajukan pembatalan di Shopee. Tahan paket ini."]);
            }
            if (! $order->shopeeShipmentArranged()) {
                throw ValidationException::withMessages(['code' => "{$order->number}: pengiriman belum diatur di Shopee. Atur pickup / drop off dulu."]);
            }
        }

        DB::transaction(function () use ($order, $userId) {
            $handover = ShipmentHandover::forToday($order->warehouse_id, $order->courier_id, $userId);

            $this->orders->ship($order, ['shipment_handover_id' => $handover->id], $userId);
        });

        return $order->refresh();
    }
}
