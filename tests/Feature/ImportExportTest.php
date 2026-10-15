<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jakarta = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->bandung = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);
        $zone = $this->jakarta->zones()->create(['code' => 'A', 'name' => 'Zona A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->jakarta->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    public function test_product_import_creates_products_with_and_without_variants(): void
    {
        $csv = "sku_induk;nama_produk;kategori;satuan;nama_varian;sku_varian;barcode;berat_gram;panjang_cm;stok_minimum;aktif\n"
            ."kaos;Kaos Polos;Pakaian;PCS;M;kaos-m;8991001;200;30,5;10;Ya\n"
            ."KAOS;Kaos Polos;Pakaian;PCS;L;KAOS-L;8991002;220;;;\n"
            ."TUMBLER;Tumbler;Peralatan;pcs;;;8992001;350;;5;Tidak\n";

        $this->post('/master/products/import', ['file' => $this->csv('produk.csv', $csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master/products/import');

        $kaos = Product::where('sku', 'KAOS')->with('variants')->firstOrFail();
        $this->assertTrue($kaos->has_variants);
        $this->assertTrue($kaos->is_active);
        $this->assertSame(['KAOS-M', 'KAOS-L'], $kaos->variants->pluck('sku')->all());
        $this->assertSame('8991001', $kaos->variants[0]->barcode);
        $this->assertSame(10, $kaos->variants[0]->min_stock);

        $tumbler = Product::where('sku', 'TUMBLER')->with('variants')->firstOrFail();
        $this->assertFalse($tumbler->has_variants);
        $this->assertFalse($tumbler->is_active);
        $this->assertSame(['TUMBLER'], $tumbler->variants->pluck('sku')->all());
        $this->assertSame(Unit::where('code', 'PCS')->value('id'), $tumbler->unit_id);
    }

    public function test_product_import_rejects_bad_rows_and_saves_nothing(): void
    {
        Product::create(['sku' => 'LAMA', 'name' => 'Lama', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'LAMA', 'barcode' => '123', 'is_active' => true]);

        $csv = "sku_induk,nama_produk,satuan,nama_varian,sku_varian,barcode,berat_gram\n"
            ."BARU,Produk Baru,PCS,,,123,\n"           // barcode milik produk lain
            ."KAOS,Kaos,PCS,M,KAOS-M,,abc\n"           // berat bukan angka
            ."KAOS,Kaos,PCS,M,KAOS-M2,,\n"             // nama varian dobel
            ."TANPA,Tanpa Satuan,,,,,\n"               // satuan wajib
            ."X Y,Spasi,PCS,,,,\n";                    // SKU tidak valid

        $this->post('/master/products/import', ['file' => $this->csv('produk.csv', $csv)])->assertSessionHasErrors('rows');

        $messages = collect(session('errors')->get('rows'))->implode(' | ');
        $this->assertStringContainsString('barcode 123 sudah dipakai', $messages);
        $this->assertStringContainsString('berat gram harus angka', $messages);
        $this->assertStringContainsString('nama varian M dobel', $messages);
        $this->assertStringContainsString('satuan wajib diisi', $messages);
        $this->assertStringContainsString('SKU induk X Y', $messages);
        $this->assertSame(1, Product::count());
    }

    public function test_product_import_updates_existing_and_adds_variants(): void
    {
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Lama', 'category' => 'Lama', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'weight_gram' => 100, 'is_active' => true]);
        $product->variants()->create(['sku' => 'KAOS-S', 'name' => 'S', 'is_active' => true]);

        $csv = "sku_induk;nama_produk;kategori;nama_varian;sku_varian;berat_gram\n"
            ."KAOS;Kaos Polos;;M;KAOS-M;250\n"
            ."KAOS;Kaos Polos;;XL;KAOS-XL;300\n";

        $this->post('/master/products/import', ['file' => $this->csv('produk.csv', $csv)])->assertSessionHasNoErrors();

        $product->refresh()->load('variants');
        $this->assertSame('Kaos Polos', $product->name);
        $this->assertSame('Lama', $product->category, 'Kolom kosong tidak menimpa nilai lama.');
        $this->assertSame(['KAOS-M', 'KAOS-S', 'KAOS-XL'], $product->variants->pluck('sku')->all());
        $this->assertSame(250, $product->variants->firstWhere('sku', 'KAOS-M')->weight_gram);
        $this->assertSame(['Produk diperbarui', 1], [session('import_summary')[1]['label'], session('import_summary')[1]['value']]);
    }

    public function test_user_without_edit_permission_cannot_update_existing_products(): void
    {
        Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        $role = Role::create(['name' => 'Input Produk']);
        $role->givePermissionTo(['products.view', 'products.create']);
        $this->actingAs(User::factory()->create()->assignRole($role));

        $this->post('/master/products/import', ['file' => $this->csv('produk.csv', "sku_induk,nama_produk\nKAOS,Kaos Baru\n")])
            ->assertSessionHasErrors('rows');
        $this->assertSame('Kaos', Product::value('name'));
    }

    public function test_exported_products_can_be_imported_back(): void
    {
        $this->post('/master/products/import', ['file' => $this->csv('produk.csv',
            "sku_induk;nama_produk;satuan;nama_varian;sku_varian;panjang_cm\nKAOS;Kaos;PCS;M;KAOS-M;30.5\nKAOS;Kaos;PCS;L;KAOS-L;\n")])
            ->assertSessionHasNoErrors();

        $response = $this->get('/master/products?export=xlsx')->assertOk()->assertDownload();
        $rows = $this->readXlsx($response->getFile()->getPathname());
        $this->assertSame('sku_induk', $rows[0][0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['KAOS', 'Kaos', '', '', 'PCS', 'M', 'KAOS-M'], array_slice($rows[1], 0, 7));
        $this->assertEquals(30.5, $rows[1][9]);

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        copy($response->getFile()->getPathname(), $path);
        $this->post('/master/products/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $this->assertSame(2, ProductVariant::count());
    }

    public function test_opening_stock_import_records_one_incoming_document(): void
    {
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $m = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $l = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        $csv = "sku;lokasi;qty;catatan\nKAOS-M;a01-01-01;10;\n8991001;;5;tanpa bin\nKAOS-L;A01-01-01;3;\n";

        $this->post('/inventory/opening-stock', [
            'warehouse_id' => $this->jakarta->id,
            'movement_date' => now()->toDateString(),
            'file' => $this->csv('stok.csv', $csv),
        ])->assertSessionHasNoErrors();

        $movement = StockMovement::with('items')->sole();
        $this->assertSame('in', $movement->type);
        $this->assertSame('Stok awal', $movement->reference);
        $this->assertCount(3, $movement->items);
        $this->assertSame(10, Stock::where('product_variant_id', $m->id)->where('warehouse_location_id', $this->bin->id)->value('qty'));
        $this->assertSame(5, Stock::where('product_variant_id', $m->id)->whereNull('warehouse_location_id')->value('qty'));
        $this->assertSame(3, Stock::where('product_variant_id', $l->id)->value('qty'));
    }

    public function test_opening_stock_import_validates_rows(): void
    {
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true]);
        $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        $csv = "sku,lokasi,qty\nKAOS,A01-01-01,2\nKAOS,ZZ-99,1\nTIDAK-ADA,,1\nKAOS,,1.5\n";

        // Lokasi A01-01-01 milik Jakarta, jadi tidak ada di Bandung.
        $this->post('/inventory/opening-stock', [
            'warehouse_id' => $this->bandung->id,
            'movement_date' => now()->toDateString(),
            'file' => $this->csv('stok.csv', $csv),
        ])->assertSessionHasErrors('rows');

        $messages = collect(session('errors')->get('rows'));
        $this->assertCount(4, $messages);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Stock::count());
    }

    public function test_opening_stock_import_respects_warehouse_access(): void
    {
        $user = User::factory()->create()->assignRole('Staff Gudang');
        $user->syncWarehouseAccess(false, [$this->bandung->id]);
        $this->actingAs($user->fresh());

        $this->post('/inventory/opening-stock', [
            'warehouse_id' => $this->jakarta->id,
            'movement_date' => now()->toDateString(),
            'file' => $this->csv('stok.csv', "sku,qty\nX,1\n"),
        ])->assertSessionHasErrors('warehouse_id');
    }

    public function test_location_import_creates_missing_zones_racks_and_skips_existing(): void
    {
        $csv = "gudang;kode_zona;nama_zona;jenis_zona;kode_rak;jumlah_level;kode_lokasi;level;posisi;jenis_lokasi;maks_qty;aktif\n"
            ."jkt01;A;;;A01;;;1;1;;;\n"                       // sudah ada -> dilewati
            ."JKT01;A;;;A01;;;2;1;Shelf;40;Ya\n"                // rak lama, lokasi baru
            ."JKT01;B;Zona B;Picking;B01;;;4;2;;;\n"            // zona & rak baru, level dari file
            ."JKT01;B;;;B01;;PLT-01;;;Pallet;;Tidak\n"
            ."BDG01;C;;Karantina;;;;;;;;\n";                    // hanya zona
        $this->post('/master/locations/import', ['file' => $this->csv('lokasi.csv', $csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master/locations/import');

        $this->assertSame(['A01-02-01', 'B01-04-02', 'PLT-01'], session('import_summary')[0]['items']);
        $this->assertSame(['A01-01-01'], session('import_summary')[2]['items']);

        $zoneB = $this->jakarta->zones()->where('code', 'B')->first();
        $this->assertSame(['Zona B', 'picking'], [$zoneB->name, $zoneB->type]);
        $this->assertSame('quarantine', $this->bandung->zones()->where('code', 'C')->value('type'));
        $rackB = $zoneB->racks()->where('code', 'B01')->first();
        $this->assertSame(4, $rackB->levels);
        $this->assertSame(3, $this->bin->rack->fresh()->levels);

        $shelf = WarehouseLocation::where('code', 'A01-02-01')->first();
        $this->assertSame(['shelf', 40, 2, 1, true], [$shelf->type, $shelf->max_qty, $shelf->level, $shelf->position, $shelf->is_active]);
        $pallet = WarehouseLocation::where('code', 'PLT-01')->first();
        $this->assertSame([$rackB->id, 'pallet', false], [$pallet->warehouse_rack_id, $pallet->type, $pallet->is_active]);
        $this->assertSame(4, WarehouseLocation::count());

        // Unggah ulang file yang sama tidak menambah apa pun.
        $this->post('/master/locations/import', ['file' => $this->csv('lokasi.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertSame(4, WarehouseLocation::count());
        $this->assertSame(0, session('import_summary')[0]['value']);
    }

    public function test_location_import_rejects_bad_rows_and_saves_nothing(): void
    {
        $csv = "gudang,kode_zona,kode_rak,kode_lokasi,level,posisi,jenis_lokasi\n"
            ."XXX,A,A01,,1,1,\n"            // gudang tidak ada
            ."JKT01,B,A01,,1,2,\n"          // rak A01 sudah di zona A
            ."JKT01,A,A01,,5,1,\n"          // melebihi level rak
            ."JKT01,A,,X-1,,,\n"            // lokasi tanpa rak
            ."JKT01,A,A02,,1,,\n"           // kode tidak bisa dibuat
            ."JKT01,A,A02,Z-1,,,Gudang\n"   // jenis tidak dikenal
            ."JKT01,A,A02,Z-2,,,\n"
            ."JKT01,A,A02,z-2,,,\n";        // dobel
        $this->post('/master/locations/import', ['file' => $this->csv('lokasi.csv', $csv)])->assertSessionHasErrors('rows');

        $this->assertCount(7, session('errors')->get('rows'));
        $this->assertSame(1, WarehouseLocation::count());
        $this->assertSame(0, WarehouseRack::where('code', 'A02')->count());
        $this->assertSame(0, WarehouseZone::where('code', 'B')->count());
    }

    public function test_exported_locations_can_be_imported_back_and_access_is_enforced(): void
    {
        $response = $this->get('/master/locations?export=xlsx')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $rows = $this->readXlsx($path);
        $rows[1][3] = 'A01-03-09';
        $rows[1][5] = 9;
        $copy = tempnam(sys_get_temp_dir(), 'loc').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($copy);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        $this->post('/master/locations/import', ['file' => new UploadedFile($copy, 'lokasi.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $this->assertTrue(WarehouseLocation::where('code', 'A01-03-09')->exists());

        $user = User::factory()->create()->assignRole('Staff Gudang');
        $user->syncWarehouseAccess(false, [$this->bandung->id]);
        $this->actingAs($user->fresh());
        if ($user->can('warehouses.create')) {
            $this->post('/master/locations/import', ['file' => $this->csv('lokasi.csv', "gudang,kode_zona\nJKT01,Z\n")])->assertSessionHasErrors('rows');
        } else {
            $this->get('/master/locations/import')->assertForbidden();
        }
        $this->assertSame(0, WarehouseZone::withoutGlobalScopes()->where('code', 'Z')->count());
    }

    public function test_import_pages_and_templates(): void
    {
        $this->get('/master/products/import')->assertOk()->assertSee('sku_induk');
        $this->get('/master/products/import/template.xlsx')->assertOk()->assertDownload('template-impor-produk.xlsx');
        $this->get('/master/products/import/template.csv')->assertOk()->assertDownload('template-impor-produk.csv');
        $this->get('/inventory/opening-stock')->assertOk()->assertSee('JKT01');
        $this->get('/inventory/opening-stock/template.xlsx')->assertOk()->assertDownload('template-impor-stok-awal.xlsx');
        $this->get('/inventory/opening-stock/template.pdf')->assertNotFound();
        $this->get('/master/locations/import')->assertOk()->assertSee('kode_rak');
        $this->get('/master/locations/import/template.xlsx')->assertOk()->assertDownload('template-impor-lokasi.xlsx');
    }

    public function test_every_list_exports_to_excel(): void
    {
        foreach ([
            '/master/products', '/master/bundles', '/master/suppliers', '/master/couriers', '/master/locations',
            '/inventory/stocks', '/inventory/stocks?view=variant', '/inventory/movements', '/inventory/receipts', '/inventory/counts',
            '/sales/orders', '/sales/returns',
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('Ekspor Excel');
            $response = $this->get($url.(str_contains($url, '?') ? '&' : '?').'export=xlsx');
            $response->assertOk();
            $this->assertStringEndsWith('.xlsx', $response->headers->get('content-disposition'), $url);
        }
    }

    public function test_stock_export_only_contains_accessible_warehouses(): void
    {
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);
        Stock::create(['product_variant_id' => $variant->id, 'warehouse_id' => $this->jakarta->id, 'warehouse_location_id' => $this->bin->id, 'qty' => 4]);
        Stock::create(['product_variant_id' => $variant->id, 'warehouse_id' => $this->bandung->id, 'warehouse_location_id' => null, 'qty' => 9]);

        $user = User::factory()->create()->assignRole('Staff Gudang');
        $user->syncWarehouseAccess(false, [$this->bandung->id]);
        $this->actingAs($user->fresh());

        $rows = $this->readXlsx($this->get('/inventory/stocks?export=xlsx')->getFile()->getPathname());
        $this->assertCount(2, $rows);
        $this->assertSame(['BDG01', 'Tanpa bin', 'KAOS'], array_slice($rows[1], 0, 3));
        $this->assertSame(9, $rows[1][7]);
    }
}
