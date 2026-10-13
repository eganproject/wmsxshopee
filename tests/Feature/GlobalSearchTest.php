<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jakarta = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->bandung = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);
        Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber Textile', 'is_active' => true]);

        $this->order('SO2610-001', $this->jakarta, 'JX1234567890', 'Budi');
        $this->order('SO2610-002', $this->bandung, 'JX9999999999', 'Ani');
    }

    private function order(string $number, Warehouse $warehouse, string $resi, string $customer): SalesOrder
    {
        return SalesOrder::withoutGlobalScopes()->create([
            'number' => $number, 'status' => 'shipped', 'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(),
            'external_number' => 'SHP-'.$number, 'tracking_number' => $resi, 'customer_name' => $customer,
        ]);
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * @return array<string, list<string>> judul hasil per grup
     */
    private function search(string $term, ?User $user = null): array
    {
        $response = $this->actingAs($user ?? $this->superAdmin())->getJson('/search?q='.urlencode($term))->assertOk();

        return collect($response->json('groups'))->mapWithKeys(fn ($group) => [$group['key'] => array_column($group['items'], 'title')])->all();
    }

    public function test_finds_products_by_sku_barcode_and_name(): void
    {
        $this->assertSame(['KAOS-M'], $this->search('8991001')['products']);
        $this->assertSame(['KAOS-L', 'KAOS-M'], $this->search('kaos polos')['products']);
        // Kecocokan persis (mis. hasil scan) di urutan pertama.
        $this->assertSame('KAOS-M', $this->search('KAOS-M')['products'][0]);
    }

    public function test_finds_orders_by_resi_marketplace_number_and_customer(): void
    {
        $this->assertSame(['SO2610-001 · SHP-SO2610-001'], $this->search('JX1234567890')['orders']);
        $this->assertSame(['SO2610-002 · SHP-SO2610-002'], $this->search('SHP-SO2610-002')['orders']);
        $this->assertSame(['SO2610-001 · SHP-SO2610-001'], $this->search('budi')['orders']);

        $item = $this->getJson('/search?q=JX1234567890')->json('groups.0.items.0');
        $this->assertSame(route('sales.orders.show', SalesOrder::where('number', 'SO2610-001')->value('id')), $item['url']);
        $this->assertSame('Dikirim', $item['badge']);
    }

    public function test_limited_user_only_finds_own_warehouse_orders(): void
    {
        $staff = User::factory()->create()->assignRole('Staff Gudang');
        $staff->syncWarehouseAccess(false, [$this->jakarta->id]);

        $this->assertSame(['SO2610-001 · SHP-SO2610-001'], $this->search('SO2610', $staff->fresh())['orders']);
        $this->assertArrayNotHasKey('orders', $this->search('JX9999999999', $staff->fresh()));
    }

    public function test_groups_follow_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.view');

        $results = $this->search('SO2610', $user);
        $this->assertArrayNotHasKey('orders', $results);
        $this->assertArrayHasKey('products', $this->search('KAOS', $user));
        $this->assertArrayNotHasKey('suppliers', $this->search('Sumber', $user));
        // Tanpa hak ubah, hasil produk membuka daftar produk yang sudah tersaring.
        $this->assertStringContainsString('/master/products?search=KAOS', $this->getJson('/search?q=KAOS-M')->json('groups.0.items.0.url'));
    }

    public function test_short_query_returns_nothing_and_guest_is_redirected(): void
    {
        $this->assertSame([], $this->search('K'));

        auth()->logout();
        $this->get('/search?q=KAOS')->assertRedirect('/login');
    }

    public function test_layout_renders_search_trigger(): void
    {
        $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk()
            ->assertSee('data-testid="global-search-trigger"', false)
            ->assertSee('Buat Pesanan Baru');
    }
}
