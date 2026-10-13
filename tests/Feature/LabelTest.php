<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\Barcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ProductVariant $hitam;

    private ProductVariant $putih;

    private Bundle $bundle;

    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->hitam = $product->variants()->create(['sku' => 'KAOS-HTM', 'name' => 'Hitam', 'barcode' => '8991234500011', 'is_active' => true]);
        $this->putih = $product->variants()->create(['sku' => 'KAOS-PTH', 'name' => 'Putih', 'is_active' => true]);
        $this->bundle = Bundle::create(['sku' => 'PKT-3', 'name' => 'Paket 3 Kaos', 'price' => 99000, 'is_active' => true]);

        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => 'A01-02-03', 'level' => 2, 'position' => 3, 'type' => 'bin', 'is_active' => true]);
        $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => 'A01-02-04', 'type' => 'bin', 'is_active' => true]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    public function test_product_page_preselects_all_variants_of_checked_products_and_bundles(): void
    {
        $this->get(route('labels.products', ['products' => [$this->hitam->product_id], 'bundles' => [$this->bundle->id]]))
            ->assertOk()
            ->assertSee('KAOS-HTM')
            ->assertSee('KAOS-PTH')
            ->assertSee('PKT-3');
    }

    public function test_product_labels_print_requested_copies_with_barcode_fallback_to_sku(): void
    {
        $response = $this->post(route('labels.products.print'), [
            'size' => 'thermal-50x30',
            'symbol' => 'barcode',
            'content' => 'barcode',
            'show_name' => 1,
            'show_variant' => 1,
            'show_sku' => 1,
            'qty' => ["v-{$this->hitam->id}" => 3, "v-{$this->putih->id}" => 1, "b-{$this->bundle->id}" => 2],
        ])->assertOk();

        $html = $response->getContent();
        $this->assertSame(6, substr_count($html, '<div class="label">'));
        // Varian berbarcode memakai barcodenya, varian & paket tanpa barcode memakai SKU.
        $this->assertSame(3, substr_count($html, 'class="value mono" data-fit>8991234500011<'));
        $this->assertSame(1, substr_count($html, 'class="value mono" data-fit>KAOS-PTH<'));
        $this->assertSame(2, substr_count($html, 'class="value mono" data-fit>PKT-3<'));
        $response->assertSee('size: 50mm 30mm', false)->assertSee('6 label');
    }

    public function test_sku_content_and_qr_code(): void
    {
        $this->post(route('labels.products.print'), [
            'size' => 'thermal-100x50',
            'symbol' => 'both',
            'content' => 'sku',
            'qty' => ["v-{$this->hitam->id}" => 1],
        ])
            ->assertOk()
            ->assertSee(Barcode::qr('KAOS-HTM'), false)
            ->assertSee(Barcode::code128('KAOS-HTM'), false)
            ->assertDontSee('>8991234500011<', false);
    }

    public function test_a4_sheet_skips_used_stickers(): void
    {
        $html = $this->post(route('labels.locations.print'), [
            'size' => 'a4-24',
            'symbol' => 'barcode',
            'show_path' => 1,
            'offset' => 5,
            'qty' => ["l-{$this->bin->id}" => 20],
        ])->assertOk()->assertSee('Gudang JKT01 · Zona A · Rak A01')->assertSee('Level 2 · Posisi 3')->getContent();

        // 5 sel kosong + 20 label = 25 sel, jadi 2 lembar A4 (24 per lembar).
        $this->assertSame(25, substr_count($html, '<div class="label">'));
        $this->assertSame(2, substr_count($html, '<div class="page sheet">'));
    }

    public function test_location_page_can_take_all_filtered_locations(): void
    {
        $this->get(route('labels.locations', ['all' => 1, 'search' => 'A01-02']))
            ->assertOk()
            ->assertSee('A01-02-03')
            ->assertSee('A01-02-04');

        $this->getJson(route('labels.locations.lookup', ['rack' => $this->bin->warehouse_rack_id]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.value', "l-{$this->bin->id}");
    }

    public function test_print_requires_at_least_one_label_and_respects_limit(): void
    {
        $this->from(route('labels.products'))
            ->post(route('labels.products.print'), ['size' => 'thermal-50x30', 'symbol' => 'barcode', 'content' => 'barcode', 'qty' => []])
            ->assertSessionHasErrors('qty');

        config(['labels.max_labels' => 10]);
        $this->post(route('labels.products.print'), ['size' => 'thermal-50x30', 'symbol' => 'barcode', 'content' => 'barcode', 'qty' => ["v-{$this->hitam->id}" => 11]])
            ->assertSessionHasErrors('qty');
    }

    public function test_label_pages_follow_view_permissions(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('warehouses.view', 'web');
        $user->givePermissionTo('warehouses.view');
        $this->actingAs($user);

        $this->get(route('labels.products'))->assertForbidden();
        $this->post(route('labels.products.print'), [])->assertForbidden();
        $this->get(route('labels.locations'))->assertOk()->assertDontSee('Label Produk');
    }
}
