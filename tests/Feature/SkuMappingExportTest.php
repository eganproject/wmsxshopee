<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class SkuMappingExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** @return array<string, list<list<mixed>>> */
    private function sheets(string $url): array
    {
        $reader = new Reader;
        $reader->open($this->get($url)->assertOk()->assertDownload()->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
    }

    public function test_shopee_mapping_export_follows_status_and_search_filters(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $bundle = Bundle::create(['sku' => 'PAKET-2', 'name' => 'Paket Dua Kaos', 'is_active' => true]);
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 901, 'shop_name' => 'Toko Oranye', 'status' => 'connected']);
        $shop->mappings()->createMany([
            ['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'KAOS-M', 'item_name' => 'Kaos', 'product_variant_id' => $variant->id, 'match_type' => 'auto', 'stock_pushed' => 7, 'stock_pushed_at' => now()],
            ['item_id' => 12, 'model_id' => 34, 'shopee_sku' => 'PAKET-2', 'item_name' => 'Paket', 'model_name' => 'Hitam', 'bundle_id' => $bundle->id, 'stock_error' => 'model_id not exist'],
            ['item_id' => 13, 'model_id' => 0, 'shopee_sku' => 'TOPI', 'item_name' => 'Topi'],
            ['item_id' => 14, 'model_id' => 0, 'shopee_sku' => 'LAMA', 'item_name' => 'Lama', 'removed_at' => now()],
        ]);
        $url = fn (array $query = []) => route('shopee.mappings.index', [$shop, ...$query, 'export' => 'xlsx']);

        $all = $this->sheets($url());
        $this->assertSame(['Pemetaan SKU', 'Info'], array_keys($all));
        $header = $all['Pemetaan SKU'][0];
        $rows = collect(array_slice($all['Pemetaan SKU'], 1))->keyBy(2);
        // Baris yang sudah tidak ada di toko hanya ikut di filter "Tidak ada di toko", sama seperti halamannya.
        $this->assertEqualsCanonicalizing(['KAOS-M', 'PAKET-2', 'TOPI'], $rows->keys()->all());
        $col = fn (string $sku, string $label) => $rows[$sku][array_search($label, $header, true)];
        $this->assertSame('Sudah dipetakan', $col('KAOS-M', 'Status'));
        $this->assertSame('Otomatis', $col('KAOS-M', 'Cara Pemetaan'));
        $this->assertSame(7, $col('KAOS-M', 'Stok Terakhir Dikirim'));
        $this->assertSame('Kaos Polos / M', $col('KAOS-M', 'Nama di Optima'));
        $this->assertSame('Paket', $col('PAKET-2', 'Dipetakan Ke'));
        $this->assertSame('34', $col('PAKET-2', 'Model ID'));
        $this->assertSame('SKU sudah tidak ada di Shopee', $col('PAKET-2', 'Gagal Kirim Stok'));
        $this->assertSame('Belum dipetakan', $col('TOPI', 'Status'));
        $this->assertContains(['Belum dipetakan', 1], $all['Info']);

        $this->assertSame(['TOPI'], array_column(array_slice($this->sheets($url(['status' => 'unmapped']))['Pemetaan SKU'], 1), 2));
        $this->assertSame(['LAMA'], array_column(array_slice($this->sheets($url(['status' => 'removed']))['Pemetaan SKU'], 1), 2));
        $searched = $this->sheets($url(['search' => 'paket']));
        $this->assertSame(['PAKET-2'], array_column(array_slice($searched['Pemetaan SKU'], 1), 2));
        $this->assertContains(['Cari', 'paket'], $searched['Info']);

        $this->get(route('shopee.mappings.index', $shop))->assertOk()->assertSee('Ekspor Excel');
    }

    public function test_tiktok_mapping_export_and_permission(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $shop = TiktokShop::create(['shop_id' => '7495000000000000009', 'shop_name' => 'Toko Hitam', 'status' => 'connected']);
        $shop->mappings()->create(['product_id' => '1729000000000000001', 'sku_id' => '1729000000000000002', 'seller_sku' => 'TOPI', 'product_name' => 'Topi']);

        $rows = $this->sheets(route('tiktok.mappings.index', [$shop, 'export' => 'xlsx']))['Pemetaan SKU'];
        $this->assertSame('Product ID', $rows[0][3]);
        // ID panjang tetap teks supaya tidak berubah jadi 1.729E+18 di Excel.
        $this->assertSame(['Topi', '', 'TOPI', '1729000000000000001', '1729000000000000002', 'Belum dipetakan'], array_slice($rows[1], 0, 6));

        $this->actingAs(User::factory()->create())->get(route('tiktok.mappings.index', [$shop, 'export' => 'xlsx']))->assertForbidden();
    }
}
