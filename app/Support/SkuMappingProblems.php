<?php

namespace App\Support;

use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Card "Masalah pemetaan" di halaman Pemetaan SKU (Shopee & TikTok): jenis
 * masalah yang ditemukan pada pemetaan toko, jumlahnya, dan tombol untuk
 * menyelesaikannya. Kunci masalah sama dengan nilai filter ?status= di halaman.
 */
class SkuMappingProblems
{
    /** Filter status yang merupakan masalah (urutan = urutan tampil di card). */
    public const KEYS = ['sku_conflict', 'failed', 'bundle_like', 'unmapped', 'inactive_target'];

    public function __construct(
        private readonly string $platform,
        private readonly ShopeeShop|TiktokShop $shop,
    ) {}

    public static function for(string $platform, ShopeeShop|TiktokShop $shop): self
    {
        return new self($platform, $shop);
    }

    /** Kolom SKU penjual di tabel pemetaan platform ini. */
    public static function skuColumn(string $platform): string
    {
        return $platform === 'shopee' ? 'shopee_sku' : 'seller_sku';
    }

    /**
     * Batasi query pemetaan ke satu jenis masalah. Baris yang sudah tidak ada di
     * toko (removed_at) tidak pernah dihitung masalah.
     */
    public static function scope(Builder $query, string $key, string $platform): Builder
    {
        $table = $query->getModel()->getTable();
        $sku = $table.'.'.self::skuColumn($platform);

        $query->whereNull("{$table}.removed_at");

        return match ($key) {
            'unmapped' => $query->whereNull("{$table}.product_variant_id")->whereNull("{$table}.bundle_id"),
            'failed' => $query->whereNotNull("{$table}.stock_error"),
            'bundle_like' => BundleLikeSku::apply($query, $sku),
            // SKU penjual persis sama dengan SKU varian/paket di gudang, tapi dipetakan ke SKU Optima yang lain.
            'sku_conflict' => $query
                ->where(fn (Builder $q) => $q->whereNotNull("{$table}.product_variant_id")->orWhereNotNull("{$table}.bundle_id"))
                ->whereNotNull($sku)
                ->where(function (Builder $q) use ($table, $sku) {
                    $variantSame = "SELECT 1 FROM product_variants WHERE UPPER(product_variants.sku) = UPPER({$sku})";
                    $bundleSame = "SELECT 1 FROM bundles WHERE UPPER(bundles.sku) = UPPER({$sku})";

                    $q->where(fn (Builder $q) => $q
                        ->whereRaw("EXISTS ({$variantSame})")
                        ->whereRaw("NOT EXISTS ({$variantSame} AND product_variants.id = COALESCE({$table}.product_variant_id, 0))"))
                        ->orWhere(fn (Builder $q) => $q
                            ->whereRaw("NOT EXISTS ({$variantSame})")
                            ->whereRaw("EXISTS ({$bundleSame})")
                            ->whereRaw("NOT EXISTS ({$bundleSame} AND bundles.id = COALESCE({$table}.bundle_id, 0))"));
                }),
            // Dipetakan ke varian/produk/paket yang sudah dinonaktifkan di Optima.
            'inactive_target' => $query->where(fn (Builder $q) => $q
                ->whereHas('variant', fn (Builder $v) => $v->where(fn (Builder $w) => $w
                    ->where('product_variants.is_active', false)
                    ->orWhereHas('product', fn (Builder $p) => $p->where('products.is_active', false))))
                ->orWhereHas('bundle', fn (Builder $b) => $b->where('bundles.is_active', false))),
            default => $query,
        };
    }

    /** @return HasMany<ShopeeSkuMapping|TiktokSkuMapping, ShopeeShop|TiktokShop> */
    private function mappings(): HasMany
    {
        return $this->shop->mappings();
    }

    private function count(string $key): int
    {
        return self::scope($this->mappings()->getQuery(), $key, $this->platform)->count();
    }

    /**
     * Daftar masalah yang ditemukan beserta tombol penyelesaiannya. Baris
     * "info" (bukan masalah, cth. SKU yang sudah tidak ada di toko) ikut di akhir.
     *
     * @return list<array{key: string, level: string, count: int, title: string, body: string, actions: list<array{label: string, href: string, method: string, icon: string, primary: bool}>}>
     */
    public function all(?User $user = null): array
    {
        $name = $this->platform === 'shopee' ? 'Shopee' : 'TikTok';
        $p = $this->platform;
        $shop = $this->shop;
        $canMap = (bool) $user?->can("{$p}.mapping");
        $canSync = (bool) $user?->can("{$p}.sync") && $shop->isSyncable() && $shop->stock_push;
        $list = fn (string $status, string $label = 'Lihat', string $icon = 'heroicon-o-list-bullet') => [
            'label' => $label, 'href' => route("{$p}.mappings.index", [$shop, 'status' => $status]), 'method' => 'GET', 'icon' => $icon, 'primary' => false,
        ];
        $problems = [];

        if ($n = $this->count('sku_conflict')) {
            $problems[] = [
                'key' => 'sku_conflict', 'level' => 'danger', 'count' => $n,
                'title' => "{$n} SKU dipetakan ke SKU Optima yang berbeda",
                'body' => "SKU {$name}-nya persis sama dengan SKU varian/paket di gudang, tapi dipetakan manual ke SKU Optima lain. Stok yang dikirim dan pesanan yang masuk memakai SKU yang salah.",
                'actions' => array_values(array_filter([
                    $canMap ? ['label' => 'Petakan ulang sesuai SKU', 'href' => route("{$p}.mappings.fix", [$shop, 'problem' => 'sku_conflict']), 'method' => 'POST', 'icon' => 'heroicon-o-arrow-path', 'primary' => true] : null,
                    $list('sku_conflict'),
                ])),
            ];
        }

        if ($n = $this->count('failed')) {
            $rows = self::scope($this->mappings()->getQuery(), 'failed', $p)->get();
            $why = StockPushError::summary($p, $rows);
            $problems[] = [
                'key' => 'failed', 'level' => 'danger', 'count' => $n,
                'title' => $why['title'],
                'body' => $why['next'],
                'actions' => array_values(array_filter([
                    $canSync ? ['label' => 'Kirim ulang stok', 'href' => route("{$p}.mappings.retry-stock", $shop), 'method' => 'POST', 'icon' => 'heroicon-o-arrow-up-tray', 'primary' => true] : null,
                    $list('failed'),
                ])),
            ];
        }

        if ($n = $this->count('bundle_like')) {
            $problems[] = [
                'key' => 'bundle_like', 'level' => 'warning', 'count' => $n,
                'title' => "{$n} SKU mirip paket dipetakan ke produk biasa",
                'body' => 'SKU terlihat seperti paket (berawalan '.implode(', ', BundleLikeSku::prefixes()).") tapi dipetakan ke produk biasa. Stok yang dikirim ke {$name} dan dicek saat pesanan masuk dihitung dari produk itu, bukan dari isi paket. Di daftar, pilih Produk Paket yang benar, buat paket barunya, atau tandai \"Bukan paket\".",
                'actions' => [$list('bundle_like', 'Perbaiki satu per satu', 'heroicon-o-wrench-screwdriver')],
            ];
            $problems[array_key_last($problems)]['actions'][0]['primary'] = $canMap;
        }

        if ($n = $this->count('unmapped')) {
            $noSku = self::scope($this->mappings()->getQuery(), 'unmapped', $p)->whereNull(self::skuColumn($p))->count();
            $held = SalesOrder::withoutGlobalScope('warehouse_access')->where($p === 'shopee' ? 'shopee_shop_id' : 'tiktok_shop_id', $shop->id)
                ->where('status', 'draft')->whereNotNull('unmapped_skus')->count();
            $body = "Stoknya tidak dikirim ke {$name}, dan pesanan berisi SKU ini tertahan di Draft.";
            $body .= $noSku ? " {$noSku} di antaranya tidak punya SKU di {$name}: isi SKU-nya di Seller Center lalu Tarik dari {$name}, atau pilih SKU Optima langsung." : '';
            $body .= $held ? " Saat ini {$held} pesanan tertahan karena SKU belum dipetakan." : '';
            $problems[] = [
                'key' => 'unmapped', 'level' => 'warning', 'count' => $n,
                'title' => "{$n} SKU belum dipetakan",
                'body' => $body,
                'actions' => array_values(array_filter([
                    $canMap && $noSku < $n ? ['label' => 'Cocokkan Otomatis', 'href' => route("{$p}.mappings.auto-match", $shop), 'method' => 'POST', 'icon' => 'heroicon-o-sparkles', 'primary' => true] : null,
                    $list('unmapped', 'Petakan manual', 'heroicon-o-pencil-square'),
                    $held ? ['label' => "{$held} pesanan tertahan", 'href' => route('sales.orders.index', ['mp' => 'unmapped', 'platform' => $p, 'dates' => 'all']), 'method' => 'GET', 'icon' => 'heroicon-o-shopping-bag', 'primary' => false] : null,
                ])),
            ];
        }

        if ($n = $this->count('inactive_target')) {
            $problems[] = [
                'key' => 'inactive_target', 'level' => 'warning', 'count' => $n,
                'title' => "{$n} SKU dipetakan ke produk/paket nonaktif",
                'body' => "Varian atau paketnya sudah dinonaktifkan di Optima, tapi SKU ini masih tayang di {$name} dan stoknya masih dikirim. Aktifkan kembali produk/paketnya, atau pilih SKU Optima lain.",
                'actions' => [$list('inactive_target', 'Lihat & perbaiki', 'heroicon-o-wrench-screwdriver')],
            ];
        }

        if ($shop->process_orders && ! $shop->stock_push) {
            $problems[] = [
                'key' => 'stock_push_off', 'level' => 'info', 'count' => 0,
                'title' => "Kirim stok ke {$name} dimatikan untuk toko ini",
                'body' => "Perubahan stok gudang tidak dikirim ke {$name} sampai kirim stok dinyalakan di daftar toko.",
                'actions' => [['label' => 'Buka daftar toko', 'href' => route('marketplace.shops', ['platform' => $p]), 'method' => 'GET', 'icon' => 'heroicon-o-building-storefront', 'primary' => false]],
            ];
        }

        if ($n = $this->mappings()->whereNotNull('removed_at')->count()) {
            $problems[] = [
                'key' => 'removed', 'level' => 'info', 'count' => $n,
                'title' => "{$n} SKU sudah tidak ada lagi di {$name}",
                'body' => 'Variasi/SKU dihapus atau diganti di toko. Barisnya disembunyikan, tidak dikirim stoknya, dan tidak dihitung gagal.',
                'actions' => [$list('removed')],
            ];
        }

        return $problems;
    }

    /** Jumlah masalah sungguhan (tanpa baris info) untuk judul card. */
    public static function issueCount(array $problems): int
    {
        return count(array_filter($problems, fn (array $p) => $p['level'] !== 'info'));
    }
}
