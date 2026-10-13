<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function record(string $type, array $items, ?string $date = null)
    {
        return $this->post('/inventory/movements', [
            'type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'movement_date' => $date ?? now()->toDateString(),
            'reference' => 'SJ-001',
            'items' => $items,
        ]);
    }

    private function qty(WarehouseLocation $location): int
    {
        return (int) Stock::where('product_variant_id', $this->variant->id)->where('warehouse_location_id', $location->id)->value('qty');
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 5]]);
        $movement = StockMovement::first();

        $this->get('/inventory/stocks')->assertOk()->assertSee('KAOS-M')->assertSee('A01-01-01');
        $this->get('/inventory/stocks?view=variant')->assertOk()->assertSee('KAOS-M');
        $this->get('/inventory/stock-card')->assertOk();
        $this->get("/inventory/stock-card?variant={$this->variant->id}")->assertOk()->assertSee($movement->number);
        $this->get('/inventory/stock-card?code=8991001')->assertOk()->assertSee($movement->number);
        $this->get('/inventory/movements')->assertOk()->assertSee($movement->number);
        $this->get("/inventory/movements/{$movement->id}")->assertOk()->assertSee('KAOS-M');

        foreach (array_keys(StockMovement::TYPES) as $type) {
            $this->get("/inventory/movements/create?type={$type}")->assertOk();
        }

        $this->get('/inventory/movements/create?type=unknown')->assertNotFound();
    }

    public function test_stock_card_and_movements_are_tabs_not_sidebar_menus(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/dashboard')->assertOk()
            ->assertSee(route('inventory.stocks.index'), false)
            ->assertDontSee(route('inventory.stock-card.index'), false)
            ->assertDontSee(route('inventory.movements.index'), false);

        $this->get('/inventory/stock-card')->assertOk()->assertSee(route('inventory.movements.index'), false);
    }

    public function test_stock_in_adds_stock_and_writes_ledger(): void
    {
        $this->actingAs($this->superAdmin());

        $this->record('in', [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 10],
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 5],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $movement = StockMovement::first();
        $this->assertSame('in', $movement->type);
        $this->assertMatchesRegularExpression('/^MSK-\d{6}-0001$/', $movement->number);
        $this->assertSame($this->superAdmin()->id, $movement->created_by);
        $this->assertSame(15, $this->qty($this->binA));
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(15, (int) StockLedger::sum('qty_in'));
    }

    public function test_stock_out_cannot_exceed_available_stock(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 3]]);

        $this->record('out', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'qty' => 4]])
            ->assertSessionHasErrors('items.0.qty');

        // Seluruh dokumen dibatalkan, saldo & kartu stok tidak berubah.
        $this->assertSame(3, $this->qty($this->binA));
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, StockLedger::count());

        $this->record('out', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'qty' => 3]])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->qty($this->binA));
        $this->assertStringStartsWith('KLR-', StockMovement::latest('id')->value('number'));
    }

    public function test_transfer_moves_stock_between_locations(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 10]]);

        $this->record('transfer', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'to_location_id' => $this->binA->id, 'qty' => 4]])
            ->assertSessionHasErrors('items.0.to_location_id');

        $this->record('transfer', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'to_location_id' => $this->binB->id, 'qty' => 4]])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, $this->qty($this->binA));
        $this->assertSame(4, $this->qty($this->binB));

        $transfer = StockMovement::where('type', 'transfer')->first();
        $this->assertSame(2, StockLedger::where('stock_movement_id', $transfer->id)->count());
    }

    public function test_adjustment_accepts_signed_qty_and_requires_permission(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 7]])->assertSessionHasNoErrors();
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => -2]])->assertSessionHasNoErrors();
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 0]])->assertSessionHasErrors('items.0.qty');
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => -6]])->assertSessionHasErrors('items.0.qty');

        $this->assertSame(5, $this->qty($this->binA));

        // Staff gudang boleh catat masuk/keluar/transfer, tapi tidak penyesuaian.
        $staff = User::factory()->create()->assignRole('Staff Gudang');
        $this->actingAs($staff);
        $this->get('/inventory/movements/create?type=adjustment')->assertForbidden();
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]])->assertForbidden();
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->qty($this->binA));
    }

    public function test_variant_lookup_searches_sku_barcode_and_name(): void
    {
        $this->actingAs($this->superAdmin());

        $expected = [['value' => $this->variant->id, 'text' => 'KAOS-M · Kaos Polos / M']];

        $this->getJson('/inventory/lookup/variants?q=kaos-m')->assertOk()->assertExactJson($expected);
        $this->getJson('/inventory/lookup/variants?q=8991001')->assertOk()->assertExactJson($expected);
        $this->getJson('/inventory/lookup/variants?q=polos')->assertOk()->assertExactJson($expected);
        $this->getJson('/inventory/lookup/variants?q=tidak-ada')->assertOk()->assertExactJson([]);
    }

    public function test_user_without_permission_cannot_access_inventory(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/inventory/stocks')->assertForbidden();
        $this->get('/inventory/stock-card')->assertForbidden();
        $this->getJson('/inventory/lookup/variants?q=KAOS')->assertForbidden();
        $this->get('/inventory/movements')->assertForbidden();
        $this->get('/inventory/movements/create?type=in')->assertForbidden();
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]])->assertForbidden();
    }

    public function test_locations_must_belong_to_selected_warehouse_and_be_active(): void
    {
        $other = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);
        $this->binB->update(['is_active' => false]);
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/movements', [
            'type' => 'in',
            'warehouse_id' => $other->id,
            'movement_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]],
        ])->assertSessionHasErrors('items.0.to_location_id');

        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binB->id, 'qty' => 1]])
            ->assertSessionHasErrors('items.0.to_location_id');

        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]], now()->addDay()->toDateString())
            ->assertSessionHasErrors('movement_date');

        $this->assertSame(0, StockMovement::count());
    }

    public function test_stock_card_shows_opening_and_running_balance(): void
    {
        $this->actingAs($this->superAdmin());
        $item = fn ($qty) => [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => $qty]];

        $this->record('in', $item(10), now()->subMonths(2)->toDateString());
        $this->record('in', $item(5), now()->toDateString());
        // Dicatat belakangan tapi bertanggal lebih awal: saldo berjalan tetap urut tanggal.
        $this->record('adjustment', $item(-3), now()->subDay()->toDateString());

        $response = $this->get('/inventory/stock-card?'.http_build_query([
            'variant' => $this->variant->id,
            'from' => now()->subWeek()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk();

        $this->assertSame(10, $response->viewData('opening'));
        $this->assertSame([7, 12], $response->viewData('rows')->pluck('balance')->all());
        $this->assertSame(5, $response->viewData('totalIn'));
        $this->assertSame(3, $response->viewData('totalOut'));
    }

    public function test_stock_list_can_group_by_variant(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('in', [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 4],
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binB->id, 'qty' => 6],
        ]);

        $byLocation = $this->get('/inventory/stocks')->viewData('stocks');
        $this->assertCount(2, $byLocation);

        $response = $this->get('/inventory/stocks?view=variant&search=8991001');
        $byVariant = $response->viewData('stocks');
        $this->assertCount(1, $byVariant);
        $this->assertSame(10, (int) $byVariant->first()->qty);
        $this->assertSame(10, $response->viewData('totalQty'));

        $this->get('/inventory/stocks?search=A01-01-02')->assertSee('A01-01-02')->assertDontSee('A01-01-01');
    }

    public function test_location_and_variant_with_stock_history_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin());
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 1]]);

        $this->delete("/master/locations/{$this->binA->id}")->assertSessionHas('error');
        $this->assertModelExists($this->binA);

        $this->delete("/master/locations/{$this->binB->id}")->assertSessionHas('success');
        $this->assertModelMissing($this->binB);

        $product = $this->variant->product;
        $this->delete("/master/products/{$product->id}")->assertSessionHas('error');
        $this->assertModelExists($product);

        $this->put("/master/products/{$product->id}", [
            'sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => $product->unit_id, 'has_variants' => '1', 'is_active' => '1',
            'variants' => [['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => '1']],
        ])->assertSessionHasErrors('variants');
        $this->assertModelExists($this->variant);
    }

    public function test_bin_location_is_optional(): void
    {
        $this->actingAs($this->superAdmin());

        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => '', 'qty' => 10]])->assertSessionHasNoErrors();
        $this->record('in', [['product_variant_id' => $this->variant->id, 'qty' => 5]])->assertSessionHasNoErrors();

        $unassigned = fn () => Stock::where('product_variant_id', $this->variant->id)->whereNull('warehouse_location_id');
        $this->assertSame(1, $unassigned()->count());
        $this->assertSame(15, (int) $unassigned()->value('qty'));
        $this->assertSame($this->warehouse->id, $unassigned()->value('warehouse_id'));

        // Put-away: pindahkan dari "Tanpa bin" ke bin.
        $this->record('transfer', [['product_variant_id' => $this->variant->id, 'from_location_id' => '', 'to_location_id' => $this->binA->id, 'qty' => 6]])
            ->assertSessionHasNoErrors();
        $this->record('transfer', [['product_variant_id' => $this->variant->id, 'from_location_id' => '', 'to_location_id' => '', 'qty' => 1]])
            ->assertSessionHasErrors('items.0.to_location_id');

        $this->record('out', [['product_variant_id' => $this->variant->id, 'from_location_id' => '', 'qty' => 10]])
            ->assertSessionHasErrors('items.0.qty');
        $this->record('out', [['product_variant_id' => $this->variant->id, 'from_location_id' => '', 'qty' => 9]])->assertSessionHasNoErrors();
        $this->record('adjustment', [['product_variant_id' => $this->variant->id, 'to_location_id' => '', 'qty' => 2]])->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $unassigned()->value('qty'));
        $this->assertSame(6, $this->qty($this->binA));
        $this->assertSame(5, StockLedger::whereNull('warehouse_location_id')->count());

        $this->get('/inventory/stocks')->assertOk()->assertSee('Tanpa bin');
        $this->get("/inventory/stock-card?variant={$this->variant->id}&location=none")->assertOk()->assertSee('Tanpa bin');
        $this->get('/inventory/movements/'.StockMovement::where('type', 'out')->value('id'))->assertOk()->assertSee('Tanpa bin');
    }
}
