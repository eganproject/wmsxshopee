<?php

namespace App\Support;

use App\Models\BarcodeScanMiss;
use App\Models\Bundle;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;

/**
 * Barcode alias per varian dan log "barcode tidak dikenali".
 *
 * Satu kode hanya boleh dipakai satu barang: SKU varian, barcode utama varian,
 * SKU / barcode paket, atau barcode alias.
 */
class Barcodes
{
    /** @var array<int, list<string>>|null Semua alias per varian, dimuat sekali per proses. */
    private static ?array $all = null;

    /**
     * Alias satu varian (huruf besar) untuk daftar varian di form scan.
     *
     * @return list<string>
     */
    public static function aliasesOf(int $variantId): array
    {
        self::$all ??= self::aliasMap();

        return self::$all[$variantId] ?? [];
    }

    /** Dipanggil saat alias berubah supaya aliasesOf() memuat ulang. */
    public static function flush(): void
    {
        self::$all = null;
    }

    /**
     * Barcode alias per varian dalam huruf besar, untuk daftar varian yang
     * dicocokkan di browser / HP saat scan.
     *
     * @param  iterable<int>|null  $variantIds  null = semua varian
     * @return array<int, list<string>>
     */
    public static function aliasMap(?iterable $variantIds = null): array
    {
        $ids = $variantIds === null ? null : collect($variantIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return ProductBarcode::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('product_variant_id', $ids))
            ->orderBy('barcode')
            ->get(['product_variant_id', 'barcode'])
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => mb_strtoupper($row->barcode))->values()->all())
            ->all();
    }

    /**
     * Barang yang sudah memakai kode ini, atau null bila kode masih bebas.
     */
    public static function takenBy(string $code, ?int $ignoreAliasId = null): ?string
    {
        $code = trim($code);

        $variant = ProductVariant::query()->where('sku', $code)->orWhere('barcode', $code)->first(['id', 'sku', 'barcode']);
        if ($variant) {
            return mb_strtoupper($variant->sku) === mb_strtoupper($code) ? "SKU varian {$variant->sku}" : "barcode varian {$variant->sku}";
        }

        $bundle = Bundle::query()->where('sku', $code)->orWhere('barcode', $code)->first(['id', 'sku']);
        if ($bundle) {
            return "produk paket {$bundle->sku}";
        }

        $alias = ProductBarcode::query()->with('variant:id,sku')->where('barcode', $code)
            ->when($ignoreAliasId, fn ($q) => $q->whereKeyNot($ignoreAliasId))
            ->first();

        return $alias ? "barcode alias {$alias->variant?->sku}" : null;
    }

    /**
     * Kode dikenali sistem sebagai barang, paket, lokasi bin atau pesanan.
     * Kode seperti ini bukan "barcode tidak dikenali" (mis. barang salah paket).
     */
    public static function isKnown(string $code): bool
    {
        $code = trim($code);

        return ProductVariant::query()->matchingCode($code)->exists()
            || Bundle::query()->where('sku', $code)->orWhere('barcode', $code)->exists()
            || WarehouseLocation::query()->where('code', $code)->exists()
            || SalesOrder::findByScan($code) !== null;
    }

    /**
     * Catat kode yang tidak dikenali. Satu baris terbuka per kode; scan ulang
     * menambah hitungan. Kode yang ternyata dikenali tidak dicatat.
     */
    public static function recordMiss(string $code, string $context, ?int $userId = null, ?int $warehouseId = null, ?int $orderId = null): ?BarcodeScanMiss
    {
        $code = mb_substr(trim($code), 0, 100);
        if (mb_strlen($code) < 3 || self::isKnown($code)) {
            return null;
        }

        $context = array_key_exists($context, BarcodeScanMiss::CONTEXTS) ? $context : 'other';

        try {
            return DB::transaction(function () use ($code, $context, $userId, $warehouseId, $orderId) {
                $open = BarcodeScanMiss::query()->open()->where('code', $code)->lockForUpdate()->first();

                if ($open) {
                    $open->update([
                        'scan_count' => $open->scan_count + 1,
                        'last_seen_at' => now(),
                        'context' => $context,
                        'user_id' => $userId ?? $open->user_id,
                        'warehouse_id' => $warehouseId ?? $open->warehouse_id,
                        'sales_order_id' => $orderId ?? $open->sales_order_id,
                    ]);

                    return $open;
                }

                return BarcodeScanMiss::create([
                    'code' => $code,
                    'context' => $context,
                    'user_id' => $userId,
                    'warehouse_id' => $warehouseId,
                    'sales_order_id' => $orderId,
                    'scan_count' => 1,
                    'last_seen_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // Gagal mencatat tidak boleh mengganggu proses scan.
            report($e);

            return null;
        }
    }
}
