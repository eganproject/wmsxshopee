<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    private function pcs(): Unit
    {
        return Unit::where('code', 'PCS')->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function variantProduct(array $overrides = []): array
    {
        return [
            'sku' => 'kaos-polos',
            'name' => 'Kaos Polos',
            'category' => 'Pakaian',
            'unit_id' => $this->pcs()->id,
            'has_variants' => '1',
            'is_active' => '1',
            'variants' => [
                ['sku' => 'kaos-mrh-m', 'name' => 'Merah / M', 'barcode' => '8991001', 'weight_gram' => '200', 'length_cm' => '30', 'width_cm' => '20', 'height_cm' => '2', 'min_stock' => '5', 'is_active' => '1'],
                ['sku' => 'kaos-mrh-l', 'name' => 'Merah / L', 'barcode' => '', 'weight_gram' => '220', 'is_active' => '0'],
            ],
            ...$overrides,
        ];
    }

    public function test_default_units_are_seeded_and_pages_render(): void
    {
        $this->assertNotNull($this->pcs());

        $this->actingAs($this->superAdmin());
        $this->post('/master/products', $this->variantProduct());
        $product = Product::first();

        $this->get('/master/products')->assertOk()->assertSee('Kaos Polos');
        $this->get('/master/products/create')->assertOk();
        $this->get("/master/products/{$product->id}/edit")->assertOk()->assertSee('KAOS-MRH-M');
        $this->get('/master/units')->assertOk()->assertSee('Pieces');
        $this->get('/master/units/create')->assertOk();
        $this->get("/master/units/{$this->pcs()->id}/edit")->assertOk();
    }

    public function test_can_create_product_with_variants_and_images(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/products', $this->variantProduct([
            'images' => [UploadedFile::fake()->image('depan.jpg'), UploadedFile::fake()->image('belakang.png')],
            'primary_image' => 'new-1',
        ]))->assertRedirect(route('master.products.index'));

        $product = Product::where('sku', 'KAOS-POLOS')->first();
        $this->assertTrue($product->has_variants);
        $this->assertSame(['KAOS-MRH-M', 'KAOS-MRH-L'], $product->variants->pluck('sku')->all());

        $first = $product->variants->first();
        $this->assertSame('8991001', $first->barcode);
        $this->assertSame(200, $first->weight_gram);
        $this->assertSame('30 × 20 × 2 cm', $first->dimensionsLabel());
        $this->assertSame(5, $first->min_stock);
        $this->assertNull($product->variants->last()->min_stock);
        $this->assertNull($product->variants->last()->barcode);
        $this->assertFalse($product->variants->last()->is_active);

        $this->assertCount(2, $product->images);
        $this->assertSame(1, $product->images->where('is_primary', true)->count());
        $this->assertSame($product->images->sortBy('sort_order')->last()->id, $product->primaryImage->id);
        $product->images->each(fn ($image) => Storage::disk('public')->assertExists($image->path));

        // Pencarian dengan barcode varian menemukan produknya.
        $this->get('/master/products?search=8991001')->assertOk()->assertSee('Kaos Polos');
    }

    public function test_product_without_variants_gets_single_default_variant(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/products', [
            'sku' => 'mug-01',
            'name' => 'Mug Keramik',
            'unit_id' => $this->pcs()->id,
            'has_variants' => '0',
            'is_active' => '1',
            'variants' => [
                ['sku' => 'diabaikan', 'name' => 'diabaikan', 'barcode' => '12345', 'weight_gram' => '350'],
                ['sku' => 'lain', 'name' => 'lain'],
            ],
            'images' => [UploadedFile::fake()->image('mug.jpg')],
        ])->assertRedirect(route('master.products.index'));

        $product = Product::where('sku', 'MUG-01')->first();
        $this->assertFalse($product->has_variants);
        $this->assertCount(1, $product->variants);
        $this->assertSame('MUG-01', $product->variants->first()->sku);
        $this->assertNull($product->variants->first()->name);
        $this->assertSame('12345', $product->variants->first()->barcode);
        // Gambar pertama otomatis jadi gambar utama.
        $this->assertNotNull($product->primaryImage);
    }

    public function test_can_update_variants_and_images(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/master/products', $this->variantProduct([
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]));
        $product = Product::first();
        [$medium, $large] = $product->variants->all();
        [$imageA, $imageB] = $product->images->sortBy('sort_order')->values()->all();
        $this->assertTrue($imageA->is_primary);

        $this->put("/master/products/{$product->id}", $this->variantProduct([
            'variants' => [
                ['id' => $medium->id, 'sku' => 'kaos-mrh-m', 'name' => 'Merah / M', 'barcode' => '8991001', 'weight_gram' => '210', 'is_active' => '1'],
                ['sku' => 'kaos-hjau-m', 'name' => 'Hijau / M', 'is_active' => '1'],
            ],
            'remove_images' => [$imageA->id],
            'images' => [UploadedFile::fake()->image('c.jpg')],
        ]))->assertRedirect(route('master.products.index'));

        $product->refresh();
        $this->assertSame(['KAOS-MRH-M', 'KAOS-HJAU-M'], $product->variants->pluck('sku')->all());
        $this->assertSame(210, $product->variants->first()->weight_gram);
        $this->assertModelMissing($large);

        $this->assertModelMissing($imageA);
        Storage::disk('public')->assertMissing($imageA->path);
        $this->assertCount(2, $product->images);
        // Gambar utama dihapus: gambar tersisa pertama jadi utama.
        $this->assertSame($imageB->id, $product->primaryImage->id);

        // Mengubah gambar utama ke gambar lain.
        $newImage = $product->images->firstWhere('id', '!=', $imageB->id);
        $this->put("/master/products/{$product->id}", $this->variantProduct([
            'variants' => $product->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->name, 'is_active' => '1'])->all(),
            'primary_image' => (string) $newImage->id,
        ]))->assertSessionHasNoErrors();
        $this->assertSame($newImage->id, $product->fresh()->primaryImage->id);
    }

    public function test_product_validation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/master/products', $this->variantProduct());

        // SKU induk, SKU varian & barcode bentrok dengan produk lain; varian duplikat.
        $this->post('/master/products', $this->variantProduct([
            'variants' => [
                ['sku' => 'kaos-mrh-m', 'name' => 'A', 'barcode' => '8991001'],
                ['sku' => 'baru', 'name' => 'A', 'weight_gram' => '-1'],
            ],
        ]))->assertSessionHasErrors(['sku', 'variants.0.sku', 'variants.0.barcode', 'variants.1.name', 'variants.1.weight_gram']);

        $this->post('/master/products', [
            'sku' => 'x',
            'name' => 'X',
            'unit_id' => 999,
            'variants' => [['sku' => 'x']],
            'images' => [UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors(['unit_id', 'images.0']);

        $this->post('/master/products', [
            'sku' => 'banyak-gambar',
            'name' => 'Banyak Gambar',
            'unit_id' => $this->pcs()->id,
            'variants' => [['sku' => 'x']],
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 10)),
        ])->assertSessionHasErrors(['images']);
    }

    public function test_cannot_take_over_variant_of_another_product(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/products', $this->variantProduct());
        $other = ProductVariant::first();

        $this->post('/master/products', [
            'sku' => 'lain',
            'name' => 'Lain',
            'unit_id' => $this->pcs()->id,
            'variants' => [['id' => $other->id, 'sku' => 'lain']],
        ])->assertSessionHasErrors(['variants.0.id']);
    }

    public function test_deleting_product_removes_variants_and_image_files(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/products', $this->variantProduct([
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ]));
        $product = Product::first();
        $path = $product->images->first()->path;

        $this->delete("/master/products/{$product->id}")->assertRedirect(route('master.products.index'));

        $this->assertModelMissing($product);
        $this->assertSame(0, ProductVariant::count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_unit_crud_and_delete_guard(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/master/units', ['code' => 'dus', 'name' => 'Dus', 'is_active' => '1'])
            ->assertRedirect(route('master.units.index'));
        $this->assertNotNull(Unit::where('code', 'DUS')->first());

        $this->post('/master/units', ['code' => 'pcs', 'name' => 'Duplikat'])->assertSessionHasErrors('code');

        $this->post('/master/products', $this->variantProduct());
        $this->delete("/master/units/{$this->pcs()->id}")->assertSessionHas('error');
        $this->assertNotNull($this->pcs());
    }

    public function test_product_permissions_are_enforced(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/products', $this->variantProduct());
        $product = Product::first();
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/master/products')->assertOk();
        $this->get('/master/units')->assertOk();
        $this->get('/master/products/create')->assertForbidden();
        $this->put("/master/products/{$product->id}", $this->variantProduct())->assertForbidden();
        $this->delete("/master/products/{$product->id}")->assertForbidden();
        $this->post('/master/units', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }
}
