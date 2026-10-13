<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseZone;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseZone $zoneA;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private WarehouseLocation $binC;

    private ProductVariant $variant;

    private ProductVariant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->zoneA = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $zoneB = $this->warehouse->zones()->create(['code' => 'B', 'name' => 'Penyimpanan B', 'type' => 'storage', 'is_active' => true]);
        $rackA = $this->zoneA->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $rackB = $zoneB->racks()->create(['code' => 'B01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rackA->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rackB->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'B01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binC = $rackA->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->other = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        // KAOS-M: 10 di A01-01-01, 4 di B01-01-01. KAOS-L: 5 tanpa bin.
        $this->stockIn([
            [$this->variant, $this->binA, 10],
            [$this->variant, $this->binB, 4],
            [$this->other, null, 5],
        ]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    /** @param  array<int, array{0: ProductVariant, 1: ?WarehouseLocation, 2: int}>  $rows */
    private function stockIn(array $rows, string $type = 'in'): void
    {
        app(StockService::class)->record(
            ['type' => $type, 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            array_map(fn ($row) => [
                'product_variant_id' => $row[0]->id,
                $type === 'out' ? 'from_location_id' : 'to_location_id' => $row[1]?->id,
                'qty' => $row[2],
            ], $rows),
        );
    }

    private function qty(ProductVariant $variant, ?WarehouseLocation $location): int
    {
        return (int) Stock::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('warehouse_location_id', $location?->id)
            ->value('qty');
    }

    private function createCount(string $scope = 'all', ?int $zoneId = null): StockCount
    {
        $this->post(route('inventory.counts.store'), [
            'warehouse_id' => $this->warehouse->id,
            'scope' => $scope,
            'warehouse_zone_id' => $zoneId,
            'count_date' => now()->toDateString(),
        ])->assertRedirect();

        return StockCount::latest('id')->firstOrFail();
    }

    /**
     * Isi hasil hitung per "SKU@lokasi" (lokasi null = tanpa bin).
     *
     * @param  array<string, int|null|array<string, mixed>>  $counts
     * @param  array<int, array<string, mixed>>  $extra  baris baru
     * @return array<int, array<string, mixed>>
     */
    private function lines(StockCount $count, array $counts, array $extra = []): array
    {
        return $count->items()->with('variant', 'location')->get()->map(function ($item) use ($counts) {
            $value = $counts[$item->variant->sku.'@'.($item->location?->code ?? 'none')] ?? null;

            return ['id' => $item->id, 'counted_qty' => is_array($value) ? $value['counted_qty'] : $value, ...(is_array($value) ? $value : [])];
        })->concat($extra)->values()->all();
    }

    private function submit(StockCount $count, array $lines, string $action = 'save')
    {
        return $this->put(route('inventory.counts.update', $count), ['action' => $action, 'payload' => json_encode($lines)]);
    }

    public function test_sheet_is_created_from_current_stock_including_unbinned(): void
    {
        $count = $this->createCount();

        $this->assertMatchesRegularExpression('/^OP\d{4}-001$/', $count->number);
        $this->assertSame('draft', $count->status);
        $this->assertSame(3, $count->items()->count());
        $this->assertSame(5, $count->items()->whereNull('warehouse_location_id')->value('system_qty'));
        $this->assertSame(10, $count->items()->where('warehouse_location_id', $this->binA->id)->value('system_qty'));
    }

    public function test_zone_and_unbinned_scopes_limit_the_lines(): void
    {
        $zone = $this->createCount('zone', $this->zoneA->id);
        $this->assertSame([$this->binA->id], $zone->items()->pluck('warehouse_location_id')->all());

        $unbinned = $this->createCount('unbinned');
        $this->assertSame([null], $unbinned->items()->pluck('warehouse_location_id')->all());
        $this->assertSame('OP'.now()->format('ym').'-002', $unbinned->number);

        $this->post(route('inventory.counts.store'), [
            'warehouse_id' => $this->warehouse->id, 'scope' => 'zone', 'count_date' => now()->toDateString(),
        ])->assertSessionHasErrors('warehouse_zone_id');
    }

    public function test_posting_sets_stock_to_counted_qty_and_skips_uncounted_lines(): void
    {
        $count = $this->createCount();

        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 8, 'KAOS-M@B01-01-01' => null, 'KAOS-L@none' => 7]), 'post')
            ->assertRedirect(route('inventory.counts.show', $count))
            ->assertSessionHasNoErrors();

        $count->refresh();
        $this->assertSame('posted', $count->status);
        $this->assertSame(8, $this->qty($this->variant, $this->binA));
        $this->assertSame(4, $this->qty($this->variant, $this->binB));
        $this->assertSame(7, $this->qty($this->other, null));

        $movement = $count->movement;
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame($count->number, $movement->reference);
        $this->assertEqualsCanonicalizing([-2, 2], $movement->items->pluck('qty')->all());

        // Terkunci setelah diposting.
        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 1]))->assertSessionHasErrors('count');
        $this->delete(route('inventory.counts.destroy', $count))->assertForbidden();
    }

    public function test_empty_count_is_not_treated_as_zero(): void
    {
        $count = $this->createCount();
        $lines = $this->lines($count, []);
        $lines[0]['counted_qty'] = '';

        $this->submit($count, $lines)->assertSessionHasNoErrors();
        $this->assertSame(0, $count->items()->whereNotNull('counted_qty')->count());

        $this->submit($count, $lines, 'post')->assertSessionHasErrors('count');
        $this->assertSame(10, $this->qty($this->variant, $this->binA));
    }

    public function test_unbinned_stock_can_be_counted_into_a_bin(): void
    {
        $count = $this->createCount('unbinned');

        $this->submit($count, $this->lines($count, ['KAOS-L@none' => ['counted_qty' => 6, 'target_location_id' => $this->binC->id]]), 'post')
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->qty($this->other, null));
        $this->assertSame(6, $this->qty($this->other, $this->binC));
        $this->assertSame($this->binC->id, $count->items()->value('target_location_id'));
    }

    public function test_found_items_can_be_added_and_duplicates_are_rejected(): void
    {
        $count = $this->createCount('zone', $this->zoneA->id);

        // Barang ditemukan di bin A01-01-02 yang belum ada di sistem.
        $new = ['id' => null, 'product_variant_id' => $this->other->id, 'warehouse_location_id' => $this->binC->id, 'counted_qty' => 3];
        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 10], [$new]))->assertSessionHasNoErrors();
        $this->assertSame(2, $count->items()->count());

        // Lokasi di luar zona ditolak; baris ganda ditolak.
        $outside = [...$new, 'warehouse_location_id' => $this->binB->id];
        $this->submit($count, $this->lines($count, [], [$outside]))->assertSessionHasErrors('lines.2.warehouse_location_id');
        $duplicate = [...$new, 'product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binA->id];
        $this->submit($count, $this->lines($count, [], [$duplicate]))->assertSessionHasErrors('lines.2.product_variant_id');

        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 10, 'KAOS-L@A01-01-02' => 3]), 'post')->assertSessionHasNoErrors();
        $this->assertSame(3, $this->qty($this->other, $this->binC));
        $this->assertSame(10, $this->qty($this->variant, $this->binA));
    }

    public function test_posting_uses_latest_balance_when_stock_moved_during_count(): void
    {
        $count = $this->createCount();

        // Selama penghitungan 3 barang keluar dari A01-01-01 (10 -> 7); hitungan fisik 7.
        $this->stockIn([[$this->variant, $this->binA, 3]], 'out');

        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 7]), 'post')->assertSessionHasNoErrors();

        $this->assertSame(7, $this->qty($this->variant, $this->binA));
        $this->assertNull($count->fresh()->stock_movement_id);
        $this->assertSame(0, StockMovement::where('type', 'adjustment')->count());
    }

    public function test_posting_needs_permission(): void
    {
        $count = $this->createCount();
        $staff = User::create(['name' => 'Staff', 'username' => 'staff', 'email' => 'staff@wms.local', 'password' => 'password', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');
        $this->actingAs($staff);

        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 9]))->assertSessionHasNoErrors();
        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 9]), 'post')->assertForbidden();
        $this->assertSame(10, $this->qty($this->variant, $this->binA));
    }

    public function test_pages_render(): void
    {
        $count = $this->createCount();

        $this->get(route('inventory.counts.index'))->assertOk()->assertSee($count->number);
        $this->get(route('inventory.counts.create'))->assertOk();
        $this->get(route('inventory.counts.show', $count))->assertOk()->assertSee('Taruh ke Bin')->assertSee('KAOS-M');
        $this->get(route('inventory.counts.sheet', $count))->assertOk()->assertSee('Tanpa bin')->assertDontSee('>Sistem<', false);
        $this->get(route('inventory.counts.sheet', [$count, 'system' => 1]))->assertOk()->assertSee('Sistem');

        $this->submit($count, $this->lines($count, ['KAOS-M@A01-01-01' => 9]), 'post');
        $this->get(route('inventory.counts.show', $count))->assertOk()->assertSee('Diposting')->assertSee($count->fresh()->movement->number);
    }

    public function test_draft_can_be_deleted(): void
    {
        $count = $this->createCount();

        $this->delete(route('inventory.counts.destroy', $count))->assertRedirect(route('inventory.counts.index'));
        $this->assertModelMissing($count);
        $this->assertSame(10, $this->qty($this->variant, $this->binA));
    }
}
