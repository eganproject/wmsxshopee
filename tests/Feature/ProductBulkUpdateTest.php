<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Product $kaos;

    private Product $tumbler;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $pcs = Unit::where('code', 'PCS')->first();
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'category' => 'Pakaian', 'unit_id' => $pcs->id, 'has_variants' => true, 'is_active' => true]);
        $this->kaos->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'weight_gram' => 200, 'length_cm' => 30, 'is_active' => true]);
        $this->kaos->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'weight_gram' => 220, 'is_active' => true]);
        $this->tumbler = Product::create(['sku' => 'TUMBLER', 'name' => 'Tumbler', 'brand' => 'Optima', 'unit_id' => $pcs->id, 'has_variants' => false, 'is_active' => true]);
        $this->tumbler->variants()->create(['sku' => 'TUMBLER', 'weight_gram' => 350, 'is_active' => true]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('update.csv', $content);
    }

    /** @return array<int, array<int, mixed>> */
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

    /** Unggah lalu terapkan memakai checksum dari halaman pratinjau. */
    private function uploadAndApply(string $csv): void
    {
        $this->post('/master/products/bulk-update', ['file' => $this->csv($csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master/products/bulk-update/preview');

        $checksum = $this->get('/master/products/bulk-update/preview')->assertOk()->viewData('checksum');
        $this->post('/master/products/bulk-update/apply', ['checksum' => $checksum])->assertRedirect('/master/products/bulk-update');
    }

    public function test_page_renders_and_index_links_to_it(): void
    {
        $this->get('/master/products')->assertOk()->assertSee('Update Massal');
        $this->get('/master/products/bulk-update?category=Pakaian')->assertOk()
            ->assertSee('Pilih kolom yang akan diubah')
            ->assertSee('Sesuai filter Master Produk')
            ->assertSee('isi_per_koli');
    }

    public function test_template_contains_only_selected_columns_prefilled_per_level(): void
    {
        $response = $this->get('/master/products/bulk-update/template?'.http_build_query(['fields' => ['merek', 'nama_produk'], 'format' => 'xlsx']))
            ->assertOk()->assertDownload();
        $rows = $this->readXlsx($response->getFile()->getPathname());
        $this->assertSame(['sku_induk', 'nama_produk', 'merek'], $rows[0]);
        $this->assertSame([['KAOS', 'Kaos Polos', ''], ['TUMBLER', 'Tumbler', 'Optima']], array_slice($rows, 1));

        $response = $this->get('/master/products/bulk-update/template?'.http_build_query(['fields' => ['berat_gram', 'kategori'], 'format' => 'xlsx', 'scope' => 'filtered', 'category' => 'Pakaian']))
            ->assertOk();
        $rows = $this->readXlsx($response->getFile()->getPathname());
        $this->assertSame(['sku', 'info_sku_induk', 'info_nama_produk', 'info_nama_varian', 'kategori', 'berat_gram'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['KAOS-M', 'KAOS', 'Kaos Polos', 'M', 'Pakaian', 200], $rows[1]);

        $this->get('/master/products/bulk-update/template?'.http_build_query(['fields' => ['merek'], 'format' => 'csv', 'scope' => 'empty']))
            ->assertOk()->assertDownload('update-massal-produk-'.now()->format('Ymd-His').'.csv');

        $this->get('/master/products/bulk-update/template')->assertSessionHasErrors('fields');
    }

    public function test_updates_only_columns_in_file_and_logs_activity(): void
    {
        $this->uploadAndApply("sku;info_nama_produk;merek;berat_gram;barcode;status_varian\n"
            ."KAOS-M;Kaos Polos;Baru;250;;Nonaktif\n"
            ."KAOS-L;Kaos Polos;Baru;220;8991002;Aktif\n");

        $this->kaos->refresh();
        $this->assertSame('Baru', $this->kaos->brand);
        $this->assertSame('Kaos Polos', $this->kaos->name);
        $this->assertSame('Pakaian', $this->kaos->category);

        $m = ProductVariant::where('sku', 'KAOS-M')->first();
        $this->assertSame(250, $m->weight_gram);
        $this->assertNull($m->barcode, 'sel kosong mengosongkan nilai');
        $this->assertFalse($m->is_active);
        $this->assertEquals(30, $m->length_cm, 'kolom yang tidak ada di file tidak berubah');
        $this->assertSame('8991002', ProductVariant::where('sku', 'KAOS-L')->value('barcode'));
        $this->assertSame('Optima', $this->tumbler->fresh()->brand);

        $this->assertSame(1, ActivityLog::where('subject_type', $this->kaos->getMorphClass())->where('subject_id', $this->kaos->id)->where('event', 'updated')->count());
        $this->assertSame([], Storage::disk('local')->files('bulk-updates'));
    }

    public function test_preview_lists_changes_without_saving(): void
    {
        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku_induk;nama_produk;status_produk;kolom_aneh\nKAOS;Kaos Premium;Aktif;x\nTUMBLER;Tumbler;Nonaktif;y\n")]);

        $response = $this->get('/master/products/bulk-update/preview')->assertOk()
            ->assertSee('Kaos Premium')->assertSee('kolom_aneh')->assertSee('Terapkan 2 Perubahan');
        $this->assertSame(2, $response->viewData('analysis')['products']);
        $this->assertSame('Kaos Polos', $this->kaos->fresh()->name);

        $this->delete('/master/products/bulk-update/preview')->assertRedirect('/master/products/bulk-update');
        $this->assertSame([], Storage::disk('local')->files('bulk-updates'));
        $this->assertSame('Kaos Polos', $this->kaos->fresh()->name);
    }

    public function test_invalid_rows_are_reported_and_nothing_is_saved(): void
    {
        Bundle::create(['sku' => 'PAKET-1', 'name' => 'Paket', 'barcode' => '777', 'is_active' => true]);

        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku;nama_produk;nama_varian;barcode;berat_gram;satuan\n"
            ."KAOS-M;Kaos A;L;111;abc;PCS\n"
            ."KAOS-L;Kaos B;L;777;10;XYZ\n"
            ."TUMBLER;;Besar;111;5;PCS\n"
            ."TIDAK-ADA;X;;;;PCS\n"
            ."KAOS-M;Kaos A;M;;;PCS\n")])
            ->assertRedirect()
            ->assertSessionHasErrorsIn('bulk', 'rows');

        $errors = collect(session('errors')->getBag('bulk')->get('rows'))->implode("\n");
        $this->assertStringContainsString('Berat (gram) harus angka', $errors);
        $this->assertStringContainsString('Nama Produk berbeda dengan baris 2', $errors);
        $this->assertStringContainsString('satuan XYZ tidak ada', $errors);
        $this->assertStringContainsString('nama produk wajib diisi', $errors);
        $this->assertStringContainsString('produk TUMBLER tanpa varian', $errors);
        $this->assertStringContainsString('TIDAK-ADA tidak ditemukan', $errors);
        $this->assertStringContainsString('KAOS-M dobel dengan baris 2', $errors);
        $this->assertStringContainsString('nama varian L dipakai lebih dari satu varian', $errors);
        $this->assertStringContainsString('produk paket PAKET-1', $errors);

        $this->assertSame([], Storage::disk('local')->files('bulk-updates'));
        $this->assertSame('Kaos Polos', $this->kaos->fresh()->name);
    }

    public function test_barcodes_can_be_swapped_but_not_taken_from_other_skus(): void
    {
        $this->uploadAndApply("sku;barcode\nKAOS-M;8991009\nKAOS-L;8991001\n");
        $this->assertSame('8991001', ProductVariant::where('sku', 'KAOS-L')->value('barcode'));

        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku;barcode\nTUMBLER;8991001\n")])
            ->assertSessionHasErrorsIn('bulk', 'rows');
        $this->assertStringContainsString('sudah dipakai SKU KAOS-L', session('errors')->getBag('bulk')->first('rows'));
    }

    public function test_file_needs_key_and_known_columns(): void
    {
        $this->post('/master/products/bulk-update', ['file' => $this->csv("nama;merek\nKaos;X\n")])
            ->assertSessionHasErrorsIn('bulk', 'file');
        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku_induk;info_nama_produk\nKAOS;Kaos\n")])
            ->assertSessionHasErrorsIn('bulk', 'file');
        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku_induk;berat_gram\nKAOS;10\n")])
            ->assertSessionHasErrorsIn('bulk', 'file');
        $this->assertStringContainsString('kolom sku', session('errors')->getBag('bulk')->first('file'));
    }

    public function test_apply_refuses_when_data_changed_after_preview(): void
    {
        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku_induk;merek\nKAOS;Baru\n")]);
        $checksum = $this->get('/master/products/bulk-update/preview')->viewData('checksum');

        $this->kaos->update(['brand' => 'Lain']);
        $this->post('/master/products/bulk-update/apply', ['checksum' => $checksum])
            ->assertRedirect('/master/products/bulk-update/preview')
            ->assertSessionHas('warning');
        $this->assertSame('Lain', $this->kaos->fresh()->brand);
    }

    public function test_requires_edit_permission(): void
    {
        $role = Role::create(['name' => 'Staf Produk']);
        $role->givePermissionTo(['products.view', 'products.create']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->get('/master/products')->assertOk()->assertDontSee('Update Massal');
        $this->get('/master/products/bulk-update')->assertForbidden();
        $this->post('/master/products/bulk-update', ['file' => $this->csv("sku_induk;merek\nKAOS;X\n")])->assertForbidden();
    }
}
