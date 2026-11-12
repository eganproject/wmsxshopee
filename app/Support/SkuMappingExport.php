<?php

namespace App\Support;

use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekspor Excel halaman Pemetaan SKU (Shopee & TikTok): mengikuti pencarian dan filter status yang sedang dipakai.
 * Isi: sheet Pemetaan SKU (1 baris per SKU marketplace) dan sheet Info (toko, ringkasan, filter).
 */
class SkuMappingExport
{
    /** Pilihan filter status di halaman Pemetaan SKU. */
    public const STATUSES = [
        'unmapped' => 'Belum dipetakan',
        'mapped' => 'Sudah dipetakan',
        'failed' => 'Gagal kirim stok',
        'bundle_like' => 'Mirip paket, dipetakan ke produk',
        'removed' => 'Tidak ada di toko',
    ];

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query  Query yang sudah difilter seperti daftar di halaman.
     * @param  array<string, int>  $summary  Jumlah total / belum dipetakan / gagal / tidak ada di toko.
     */
    public static function download(Request $request, ShopeeShop|TiktokShop $shop, Builder|Relation $query, array $summary): BinaryFileResponse
    {
        $shopee = $shop instanceof ShopeeShop;
        $platform = $shopee ? 'Shopee' : 'TikTok';

        $rows = $query->lazy(500)->map(function (Model $m) use ($shopee) {
            $error = $m->stock_error ? StockPushError::explain($shopee ? 'shopee' : 'tiktok', $m->stock_error) : null;
            $status = match (true) {
                $m->removed_at !== null => XlsxCell::tone('Tidak ada di toko', 'slate'),
                $m->product_variant_id !== null || $m->bundle_id !== null => XlsxCell::tone('Sudah dipetakan', 'emerald'),
                default => XlsxCell::tone('Belum dipetakan', 'amber'),
            };

            return [
                $shopee ? $m->item_name : $m->product_name,
                $shopee ? $m->model_name : $m->sku_name,
                $shopee ? $m->shopee_sku : $m->seller_sku,
                (string) ($shopee ? $m->item_id : $m->product_id),
                $shopee ? ($m->model_id ? (string) $m->model_id : null) : (string) $m->sku_id,
                $status,
                $m->variant ? 'Varian' : ($m->bundle ? new XlsxCell('Paket', '6D28D9', null, true) : null),
                $m->variant?->sku ?? $m->bundle?->sku,
                $m->variant?->fullName() ?? $m->bundle?->name,
                match ($m->match_type) {
                    'auto' => 'Otomatis',
                    null => $m->product_variant_id || $m->bundle_id ? 'Manual' : null,
                    default => 'Manual',
                },
                $m->looksLikeBundle() ? XlsxCell::tone('Ya', 'amber') : null,
                $m->stock_pushed,
                $m->stock_pushed_at,
                $error ? XlsxCell::tone($error['title'], 'rose') : null,
                $m->stock_error,
                $error['fix'] ?? null,
                $m->removed_at,
            ];
        });

        $status = (string) $request->input('status');
        $filters = array_values(array_filter([
            isset(self::STATUSES[$status]) ? ['Status', self::STATUSES[$status]] : null,
            $request->filled('search') ? ['Cari', trim((string) $request->input('search'))] : null,
        ]));

        return XlsxExport::workbook('pemetaan-sku-'.strtolower($platform).'-'.str($shop->displayName())->slug().'-'.now()->format('Ymd-His').'.xlsx', [
            [
                'name' => 'Pemetaan SKU',
                'header' => ["Produk {$platform}", 'Variasi', ["SKU {$platform}", 'text'], [$shopee ? 'Item ID' : 'Product ID', 'text'], [$shopee ? 'Model ID' : 'SKU ID', 'text'],
                    'Status', 'Dipetakan Ke', ['SKU Optima', 'text'], 'Nama di Optima', 'Cara Pemetaan', 'Mirip Paket',
                    ['Stok Terakhir Dikirim', 'number'], ['Waktu Kirim Stok', 'datetime'], 'Gagal Kirim Stok', 'Pesan Error', 'Saran Perbaikan', ['Tidak Ada di Toko Sejak', 'date']],
                'rows' => $rows,
                'freeze' => 'B',
            ],
            [
                'name' => 'Info',
                'type' => 'info',
                'rows' => [
                    ["Pemetaan SKU {$platform}"],
                    ['Toko', $shop->displayName()],
                    ['Diekspor', now()],
                    ['Oleh', $request->user()->name],
                    [''],
                    ['Ringkasan toko'],
                    ["Total SKU {$platform}", $summary['total']],
                    ['Sudah dipetakan', $summary['total'] - $summary['unmapped']],
                    ['Belum dipetakan', $summary['unmapped']],
                    ['Gagal kirim stok', $summary['failed']],
                    ['Mirip paket', $summary['bundleLike']],
                    ['Tidak ada di toko', $summary['removed']],
                    [''],
                    ['Filter yang dipakai'],
                    ...($filters ?: [['Filter', 'Tanpa filter (semua SKU yang masih ada di toko)']]),
                    [''],
                    ['Petunjuk'],
                    ['Status', 'Belum dipetakan = stok tidak dikirim dan pesanan berisi SKU ini tertahan di Draft.'],
                    ['Cara Pemetaan', 'Otomatis = SKU marketplace sama dengan SKU di Optima; Manual = dipilih di halaman Pemetaan SKU.'],
                    ['Mirip Paket', 'SKU terlihat seperti paket tapi dipetakan ke produk biasa; stok dihitung dari produk itu, bukan isi paket.'],
                ],
            ],
        ]);
    }
}
