<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseVariantLocation;
use App\Models\WarehouseVariantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Import update SKU per Gudang: stok min/maks & lokasi tetap lewat Excel/CSV.
 */
class SkuSettingImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jkt;

    private Warehouse $sby;

    private WarehouseLocation $a1;

    private WarehouseLocation $a2;

    private ProductVariant $kaos;

    private ProductVariant $topi;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->jkt = Warehouse::create(['code' => 'JKT', 'name' => 'Jakarta', 'is_active' => true]);
        $this->sby = Warehouse::create(['code' => 'SBY', 'name' => 'Surabaya', 'is_active' => true]);

        $rack = $this->jkt->zones()->create(['code' => 'A', 'name' => 'Picking', 'type' => 'picking', 'is_active' => true])
            ->racks()->create(['code' => 'A01', 'levels' => 1, 'is_active' => true]);
        $this->a1 = $rack->locations()->create(['warehouse_id' => $this->jkt->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->a2 = $rack->locations()->create(['warehouse_id' => $this->jkt->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $unit = Unit::where('code', 'PCS')->value('id');
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'min_stock' => 5, 'is_active' => true]);
        $this->topi = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'TOPI', 'is_active' => true]);

        WarehouseVariantSetting::create(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->topi->id, 'min_qty' => 4, 'max_qty' => 8]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sku.csv', $content);
    }

    private function uploadAndApply(string $csv): void
    {
        $this->post('/inventory/sku-settings/import', ['file' => $this->csv($csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/inventory/sku-settings/import/preview');

        $checksum = $this->get('/inventory/sku-settings/import/preview')->assertOk()->viewData('checksum');
        $this->post('/inventory/sku-settings/import/apply', ['checksum' => $checksum])->assertRedirect('/inventory/sku-settings/import');
    }

    public function test_page_renders_and_index_links_to_it(): void
    {
        $this->get("/inventory/sku-settings?warehouse={$this->jkt->id}")->assertOk()->assertSee('/inventory/sku-settings/import', false);
        $this->get('/inventory/sku-settings/import')->assertOk()->assertSee('Pilih kolom yang akan diubah')->assertSee('Semua gudang');
    }

    public function test_template_contains_current_settings_and_bins(): void
    {
        WarehouseVariantLocation::create(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->topi->id, 'warehouse_location_id' => $this->a1->id, 'priority' => 1, 'capacity' => 50]);
        WarehouseVariantLocation::create(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->topi->id, 'warehouse_location_id' => $this->a2->id, 'priority' => 2]);

        $response = $this->get("/inventory/sku-settings/import/template?warehouse={$this->jkt->id}&scope=configured&format=xlsx&fields[]=stok_min&fields[]=stok_maks&fields[]=lokasi_tetap")->assertOk();

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        $this->assertSame(['gudang', 'sku', 'info_nama_produk', 'info_stok', 'info_min_master', 'stok_min', 'stok_maks', 'lokasi_tetap'], $rows[0]);
        $this->assertCount(2, $rows, 'Hanya SKU yang sudah diatur.');
        $this->assertSame(['JKT', 'TOPI'], array_slice($rows[1], 0, 2));
        $this->assertEquals([4, 8, 'A01-01-01:50, A01-01-02'], array_slice($rows[1], 5));

        // CSV semua gudang: satu baris per SKU per gudang.
        $csv = $this->get('/inventory/sku-settings/import/template?warehouse=all&scope=all&format=csv&fields[]=stok_min')->assertOk()->streamedContent();
        $this->assertSame(1 + 2 * 2, count(array_filter(explode("\n", trim($csv)))));
    }

    public function test_upload_previews_then_applies_min_max_and_bins(): void
    {
        $csv = "gudang;sku;info_nama_produk;stok_min;stok_maks;lokasi_tetap\n"
            ."jkt;kaos;Kaos;10;40;A01-01-02:30, a01-01-01\n"
            ."JKT;TOPI;Topi;;;\n"
            ."SBY;KAOS;Kaos;3;;\n";

        $this->post('/inventory/sku-settings/import', ['file' => $this->csv($csv)])->assertRedirect('/inventory/sku-settings/import/preview');
        $this->assertSame(0, WarehouseVariantLocation::count(), 'Belum ada yang disimpan sebelum diterapkan.');

        $preview = $this->get('/inventory/sku-settings/import/preview')->assertOk()->assertSee('A01-01-02:30, A01-01-01');
        $this->assertSame(3, $preview->viewData('analysis')['skus']);
        $this->post('/inventory/sku-settings/import/apply', ['checksum' => $preview->viewData('checksum')])->assertRedirect('/inventory/sku-settings/import');

        $kaos = WarehouseVariantSetting::where(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->kaos->id])->first();
        $this->assertSame([10, 40, $this->a2->id], [$kaos->min_qty, $kaos->max_qty, $kaos->default_location_id]);
        $bins = WarehouseVariantLocation::where('product_variant_id', $this->kaos->id)->orderBy('priority')->get();
        $this->assertSame([[$this->a2->id, 30], [$this->a1->id, null]], $bins->map(fn ($b) => [$b->warehouse_location_id, $b->capacity])->all());

        // Semua kolom kosong: pengaturan TOPI dihapus.
        $this->assertNull(WarehouseVariantSetting::where(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->topi->id])->first());
        $this->assertSame(3, WarehouseVariantSetting::where(['warehouse_id' => $this->sby->id, 'product_variant_id' => $this->kaos->id])->value('min_qty'));
    }

    public function test_columns_not_in_file_are_left_alone(): void
    {
        WarehouseVariantLocation::create(['warehouse_id' => $this->jkt->id, 'product_variant_id' => $this->topi->id, 'warehouse_location_id' => $this->a1->id, 'priority' => 1]);
        WarehouseVariantSetting::where('product_variant_id', $this->topi->id)->update(['default_location_id' => $this->a1->id]);

        $this->uploadAndApply("gudang;sku;stok_min\nJKT;TOPI;6\n");

        $setting = WarehouseVariantSetting::where('product_variant_id', $this->topi->id)->first();
        $this->assertSame([6, 8, $this->a1->id], [$setting->min_qty, $setting->max_qty, $setting->default_location_id]);
        $this->assertSame(1, WarehouseVariantLocation::count());
    }

    public function test_invalid_rows_are_reported_and_nothing_is_saved(): void
    {
        $csv = "gudang;sku;stok_min;stok_maks;lokasi_tetap\n"
            ."XXX;KAOS;1;;\n"
            ."JKT;NOPE;1;;\n"
            ."JKT;KAOS;abc;;\n"
            ."JKT;TOPI;5;2;\n"
            ."SBY;TOPI;1;5;A01-01-01\n"
            ."JKT;TOPI;1;;\n";

        $this->from('/inventory/sku-settings/import')->post('/inventory/sku-settings/import', ['file' => $this->csv($csv)])
            ->assertRedirect('/inventory/sku-settings/import')
            ->assertSessionHasErrorsIn('import', 'rows');

        $errors = session('errors')->getBag('import')->get('rows');
        $this->assertStringContainsString('gudang XXX tidak ditemukan', $errors[0]);
        $this->assertStringContainsString('SKU NOPE tidak ditemukan', $errors[1]);
        $this->assertStringContainsString('Stok Minimum harus bilangan bulat', $errors[2]);
        $this->assertStringContainsString('stok maksimum (2) tidak boleh lebih kecil dari stok minimum (5)', $errors[3]);
        $this->assertStringContainsString('lokasi A01-01-01 tidak ada di gudang SBY', $errors[4]);
        $this->assertStringContainsString('dobel dengan baris 5', $errors[5]);

        $this->assertSame(8, WarehouseVariantSetting::where('product_variant_id', $this->topi->id)->value('max_qty'));
        $this->assertSame(1, WarehouseVariantSetting::count());
        $this->assertSame([], Storage::disk('local')->files('bulk-updates'));
    }

    public function test_missing_key_column_is_rejected(): void
    {
        $this->post('/inventory/sku-settings/import', ['file' => $this->csv("sku;stok_min\nKAOS;1\n")])
            ->assertSessionHasErrorsIn('import', 'file');
    }

    public function test_user_without_warehouse_access_cannot_import_into_it(): void
    {
        $staff = User::factory()->create(['username' => 'staf', 'is_active' => true, 'all_warehouses' => false]);
        $staff->givePermissionTo(['inventory.view', 'inventory.create']);
        $staff->warehouses()->sync([$this->sby->id]);

        $this->actingAs($staff)->post('/inventory/sku-settings/import', ['file' => $this->csv("gudang;sku;stok_min\nJKT;KAOS;1\n")])
            ->assertSessionHasErrorsIn('import', 'rows');
        $this->assertStringContainsString('gudang JKT tidak ditemukan atau tidak bisa Anda akses', session('errors')->getBag('import')->first('rows'));
        $this->assertSame(1, WarehouseVariantSetting::withoutGlobalScope('warehouse_access')->count());
    }

    public function test_requires_inventory_create_permission(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer', 'is_active' => true]);
        $viewer->givePermissionTo('inventory.view');

        $this->actingAs($viewer)->get('/inventory/sku-settings/import')->assertForbidden();
        $this->actingAs($viewer)->get("/inventory/sku-settings?warehouse={$this->jkt->id}")->assertOk()->assertDontSee('/inventory/sku-settings/import', false);
    }
}
