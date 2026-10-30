<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private ProductVariant $variant;

    private ProductVariant $other;

    private SalesOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->other = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        $this->order = SalesOrder::create([
            'number' => 'SO2610-001',
            'status' => 'shipped',
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'channel_name' => 'Shopee Toko A',
            'external_number' => '241013ABC',
            'customer_name' => 'Budi',
            'customer_phone' => '0812',
            'tracking_number' => 'JX123',
            'shipped_at' => now(),
        ]);
        $this->order->items()->createMany([
            ['product_variant_id' => $this->variant->id, 'qty' => 3, 'price' => 75000],
            ['product_variant_id' => $this->other->id, 'qty' => 1, 'price' => 70000],
        ]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderPayload(array $items, array $overrides = []): array
    {
        return [
            'source' => 'order',
            'sales_order_id' => $this->order->id,
            'warehouse_id' => $this->warehouse->id,
            'return_date' => now()->toDateString(),
            'items' => $items,
            ...$overrides,
        ];
    }

    private function line(ProductVariant $variant): int
    {
        return $this->order->items()->where('product_variant_id', $variant->id)->value('id');
    }

    private function qty(?WarehouseLocation $location, ?ProductVariant $variant = null): int
    {
        return (int) Stock::where('product_variant_id', ($variant ?? $this->variant)->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->when($location, fn ($q) => $q->where('warehouse_location_id', $location->id), fn ($q) => $q->whereNull('warehouse_location_id'))
            ->value('qty');
    }

    public function test_lookup_finds_shipped_order_by_tracking_number_so_number_or_external_number(): void
    {
        foreach (['JX123', 'so2610-001', '241013ABC'] as $code) {
            $this->getJson(route('sales.returns.lookup', ['code' => $code]))
                ->assertOk()
                ->assertJsonPath('orders.0.number', 'SO2610-001')
                ->assertJsonPath('orders.0.items.0.sku', 'KAOS-M')
                ->assertJsonPath('orders.0.items.0.remaining', 3);
        }

        $this->getJson(route('sales.returns.lookup', ['code' => 'JX123']))->assertJsonPath('matched_tracking', true);
        $this->getJson(route('sales.returns.lookup', ['code' => 'NOPE']))->assertNotFound();

        $this->order->update(['status' => 'packed']);
        $this->getJson(route('sales.returns.lookup', ['code' => 'JX123']))->assertStatus(422)->assertJsonFragment(['message' => 'Pesanan SO2610-001 masih berstatus Sudah Dikemas. Hanya pesanan yang sudah dikirim yang bisa diretur.']);
    }

    public function test_order_return_posts_good_items_to_stock_and_records_damaged_separately(): void
    {
        Storage::fake('public');

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 2, 'qty_damaged' => 1, 'warehouse_location_id' => $this->bin->id, 'reason' => 'wrong_size', 'photo' => UploadedFile::fake()->image('retur.jpg')],
            // Barang yang tidak diisi jumlahnya tidak ikut retur.
            ['sales_order_item_id' => $this->line($this->other), 'qty_good' => '', 'qty_damaged' => '', 'reason' => ''],
        ], ['action' => 'receive']))->assertRedirect();

        $return = SalesReturn::with('items')->sole();

        $this->assertMatchesRegularExpression('/^RT\d{4}-001$/', $return->number);
        $this->assertSame('received', $return->status);
        $this->assertSame('Budi', $return->sender_name);
        $this->assertSame('JX123', $return->tracking_number);
        $this->assertSame('241013ABC', $return->external_number);
        $this->assertCount(1, $return->items);
        $this->assertSame($this->variant->id, $return->items[0]->product_variant_id);
        Storage::disk('public')->assertExists($return->items[0]->photo_path);

        // Hanya 2 layak jual yang masuk stok; 1 rusak tidak menambah stok jual.
        $this->assertSame(2, $this->qty($this->bin));
        $this->assertSame('in', $return->movement->type);
        $this->assertSame($return->number, $return->movement->reference);

        $this->assertTrue(ActivityLog::where('subject_type', $return->getMorphClass())->where('subject_id', $return->id)->where('module', 'returns')->exists());
    }

    public function test_cannot_return_more_than_shipped_including_other_returns(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 2, 'reason' => 'changed_mind'],
        ]))->assertSessionHasNoErrors();

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'qty_damaged' => 1, 'reason' => 'damaged'],
        ]))->assertSessionHasErrors('items.0.qty_good');

        $this->assertSame(1, SalesReturn::count());

        // Saat draft diubah, jumlahnya sendiri tidak dihitung sebagai "sudah diretur".
        $return = SalesReturn::sole();
        $this->put(route('sales.returns.update', $return), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 3, 'reason' => 'changed_mind'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(3, $return->items()->sum('qty_good'));
    }

    public function test_receiving_a_draft_rechecks_returns_received_meanwhile(): void
    {
        // Retur manual 2 sudah diterima, lalu ada draft otomatis lama (dibuat sebelum ada batas sisa) berisi 3.
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 2, 'warehouse_location_id' => $this->bin->id, 'reason' => 'changed_mind'],
        ], ['action' => 'receive']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->qty($this->bin));

        $auto = SalesReturn::create(['number' => 'RT-AUTO', 'status' => 'draft', 'source' => 'order', 'sales_order_id' => $this->order->id,
            'warehouse_id' => $this->warehouse->id, 'return_date' => now()->toDateString(), 'sender_name' => 'Budi']);
        $auto->items()->create(['sales_order_item_id' => $this->line($this->variant), 'product_variant_id' => $this->variant->id, 'qty_good' => 3, 'warehouse_location_id' => $this->bin->id, 'reason' => 'damaged']);

        $this->post(route('sales.returns.receive', $auto))->assertSessionHasErrors('items');
        $this->assertSame('draft', $auto->fresh()->status);
        $this->assertSame(2, $this->qty($this->bin));

        // Setelah jumlahnya disesuaikan ke sisa (1), draft bisa diterima.
        $auto->items()->update(['qty_good' => 1]);
        $this->post(route('sales.returns.receive', $auto))->assertSessionHasNoErrors();
        $this->assertSame('received', $auto->fresh()->status);
        $this->assertSame(3, $this->qty($this->bin));
    }

    public function test_auto_return_rows_are_capped_to_what_is_left(): void
    {
        $line = $this->line($this->variant);
        $other = $this->line($this->other);
        $draft = SalesReturn::create(['number' => 'RT-X', 'status' => 'draft', 'source' => 'order', 'sales_order_id' => $this->order->id,
            'warehouse_id' => $this->warehouse->id, 'return_date' => now()->toDateString(), 'sender_name' => 'Budi']);
        $draft->items()->create(['sales_order_item_id' => $line, 'product_variant_id' => $this->variant->id, 'qty_good' => 2, 'reason' => 'damaged']);

        $rows = SalesReturn::capToRemaining([
            ['sales_order_item_id' => $line, 'qty_good' => 3],
            ['sales_order_item_id' => $line, 'qty_good' => 1],
            ['sales_order_item_id' => $other, 'qty_good' => 1],
        ], [$line => 3, $other => 1]);

        $this->assertSame([[$line, 1], [$other, 1]], array_map(fn ($r) => [$r['sales_order_item_id'], $r['qty_good']], $rows));
    }

    public function test_order_return_rejects_lines_from_another_order_and_requires_a_reason(): void
    {
        $foreign = SalesOrder::create(['number' => 'SO2610-002', 'status' => 'shipped', 'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Ani']);
        $foreignLine = $foreign->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 5, 'price' => 0]);

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $foreignLine->id, 'qty_good' => 1, 'reason' => 'damaged'],
        ]))->assertSessionHasErrors('items.0.qty_good');

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => ''],
        ]))->assertSessionHasErrors('items.0.reason');

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => '', 'reason' => 'damaged'],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_manual_return_without_order(): void
    {
        $this->post(route('sales.returns.store'), [
            'source' => 'manual',
            'warehouse_id' => $this->warehouse->id,
            'return_date' => now()->toDateString(),
            'sender_name' => 'Reseller Budi',
            'channel_name' => 'Toko Offline',
            'items' => [
                ['product_variant_id' => $this->other->id, 'qty_good' => 4, 'reason' => 'changed_mind'],
                ['product_variant_id' => $this->variant->id, 'qty_damaged' => 2, 'warehouse_location_id' => $this->bin->id, 'reason' => 'damaged'],
            ],
        ])->assertSessionHasNoErrors();

        $return = SalesReturn::with('items')->sole();
        $this->assertSame('manual', $return->source);
        $this->assertNull($return->sales_order_id);
        // Lokasi bin hanya disimpan untuk barang layak jual.
        $this->assertNull($return->items[1]->warehouse_location_id);

        $this->post(route('sales.returns.receive', $return))->assertRedirect(route('sales.returns.show', $return));

        $this->assertSame(4, $this->qty(null, $this->other));
        $this->assertSame(0, $this->qty($this->bin));
        $this->assertSame('received', $return->fresh()->status);
    }

    public function test_manual_return_requires_sender_name(): void
    {
        $this->post(route('sales.returns.store'), [
            'source' => 'manual',
            'warehouse_id' => $this->warehouse->id,
            'return_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->other->id, 'qty_good' => 1, 'reason' => 'other']],
        ])->assertSessionHasErrors('sender_name');
    }

    public function test_all_damaged_return_is_received_without_stock_movement(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->other), 'qty_damaged' => 1, 'reason' => 'damaged'],
        ], ['action' => 'receive']))->assertSessionHasNoErrors();

        $return = SalesReturn::sole();
        $this->assertSame('received', $return->status);
        $this->assertNull($return->stock_movement_id);
        $this->assertSame(0, $this->qty(null, $this->other));
    }

    public function test_missing_items_are_recorded_without_stock_and_count_toward_the_return_limit(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'qty_missing' => 2, 'reason' => 'changed_mind'],
            // Baris yang isinya hanya hilang otomatis beralasan "Tidak ada di paket".
            ['sales_order_item_id' => $this->line($this->other), 'qty_missing' => 1, 'reason' => ''],
        ], ['action' => 'receive']))->assertSessionHasNoErrors();

        $return = SalesReturn::with('items')->sole();
        $this->assertSame([2, 1], $return->items->pluck('qty_missing')->all());
        $this->assertSame('missing', $return->items[1]->reason);
        $this->assertSame(1, $this->qty(null));
        $this->assertSame(0, $this->qty(null, $this->other));

        // 1 + 2 dari 3 sudah terpakai; tidak bisa diretur lagi.
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'changed_mind'],
        ]))->assertSessionHasErrors('items.0.qty_good');

        $this->get(route('sales.returns.index', ['missing' => 1]))->assertOk()->assertSee($return->number);
        $this->get(route('sales.returns.show', $return))->assertOk()->assertSee('Tidak ada di paket (hilang)');
    }

    public function test_received_return_is_locked(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'changed_mind'],
        ], ['action' => 'receive']));

        $return = SalesReturn::sole();

        $this->post(route('sales.returns.receive', $return))->assertSessionHasErrors('return');
        $this->assertSame(1, $this->qty(null));

        $this->put(route('sales.returns.update', $return), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 2, 'reason' => 'changed_mind'],
        ]))->assertForbidden();

        $this->delete(route('sales.returns.destroy', $return));
        $this->assertModelExists($return);
    }

    /** Draft retur yang diterima orang lain saat sedang disimpan tidak boleh ikut berubah. */
    public function test_editing_a_draft_received_meanwhile_is_rejected(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'damaged'],
        ]));
        $return = SalesReturn::sole();

        // Orang lain memposting draft ini tepat sebelum transaksi simpan dimulai.
        $once = false;
        Event::listen(TransactionBeginning::class, function () use (&$once, $return) {
            if (! $once) {
                $once = true;
                DB::table('sales_returns')->where('id', $return->id)->update(['status' => 'received']);
            }
        });

        $this->put(route('sales.returns.update', $return), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->other), 'qty_good' => 1, 'reason' => 'damaged'],
        ]))->assertSessionHasErrors('return');

        $this->assertSame([$this->variant->id], $return->items()->pluck('product_variant_id')->all());
    }

    public function test_updating_draft_keeps_or_removes_photos(): void
    {
        Storage::fake('public');

        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'damaged', 'photo' => UploadedFile::fake()->image('a.jpg')],
            ['sales_order_item_id' => $this->line($this->other), 'qty_damaged' => 1, 'reason' => 'damaged', 'photo' => UploadedFile::fake()->image('b.jpg')],
        ]));

        $return = SalesReturn::sole();
        [$keep, $drop] = $return->items->pluck('photo_path')->all();

        $this->put(route('sales.returns.update', $return), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'damaged', 'photo_path' => $keep],
            // Path foto asing dari luar retur ini diabaikan.
            ['sales_order_item_id' => $this->line($this->other), 'qty_damaged' => 1, 'reason' => 'damaged', 'photo_path' => 'products/other.jpg'],
        ]))->assertSessionHasNoErrors();

        $paths = $return->items()->pluck('photo_path')->all();
        $this->assertSame([$keep, null], $paths);
        Storage::disk('public')->assertExists($keep);
        Storage::disk('public')->assertMissing($drop);
    }

    public function test_pages_render(): void
    {
        $this->post(route('sales.returns.store'), $this->orderPayload([
            ['sales_order_item_id' => $this->line($this->variant), 'qty_good' => 1, 'reason' => 'changed_mind'],
        ]));
        $return = SalesReturn::sole();

        $this->get(route('sales.returns.index'))->assertOk()->assertSee($return->number);
        $this->get(route('sales.returns.create'))->assertOk()->assertSee('Scan Resi');
        $this->get(route('sales.returns.create', ['order' => $this->order->id]))->assertOk()->assertSee('SO2610-001');
        $this->get(route('sales.returns.show', $return))->assertOk()->assertSee('Pembeli berubah pikiran');
        $this->get(route('sales.returns.edit', $return))->assertOk();
        $this->get(route('sales.orders.show', $this->order))->assertOk()->assertSee('Buat Retur')->assertSee($return->number);
    }

    public function test_staff_without_permission_cannot_open_returns(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('sales.returns.index'))->assertForbidden();
    }
}
