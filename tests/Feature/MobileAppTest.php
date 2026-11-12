<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ShipmentHandover;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Halaman & endpoint aplikasi Android (/m). */
class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const APP_UA = 'Mozilla/5.0 (Linux; Android 14) OptimaGudang/1.0.0 (1)';

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->kaosL = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->kaosM->id, 'to_location_id' => $this->bin->id, 'qty' => 20],
            ['product_variant_id' => $this->kaosL->id, 'to_location_id' => null, 'qty' => 20],
        ]);

        $this->admin = User::where('username', 'superadmin')->first();
        $this->actingAs($this->admin);
    }

    private function order(array $attributes = []): SalesOrder
    {
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'items' => [['product_variant_id' => $this->kaosM->id, 'qty' => 2, 'price' => 75000]],
            'action' => 'confirm',
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->firstOrFail();
        $order->update($attributes);
        $order = $order->fresh();

        $this->post("/sales/orders/{$order->id}/pick", ['picks' => $order->items->mapWithKeys(fn ($item) => [
            $item->id => [['warehouse_location_id' => $this->bin->id, 'qty' => $item->qty]],
        ])->all()])->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_every_mobile_page_renders(): void
    {
        $order = $this->order(['tracking_number' => 'RESI-1']);

        foreach (['/m', '/m/qc', '/m/handover', '/m/receipts', '/m/receipts/create', '/m/opname', '/m/stock-check', '/m/returns', '/m/print', '/m/outbox', '/m/account', '/m/offline'] as $url) {
            $this->get($url)->assertOk()->assertSee('name="warehouse-id" content="'.$this->warehouse->id.'"', false);
        }

        $this->get('/m/scan?code=RESI-1')->assertOk()->assertSee($order->number);
        $this->get('/m/scan?code=A01-01-01')->assertOk()->assertSee('KAOS-M');
        $this->get('/m/scan?code=8991001')->assertOk()->assertSee('A01-01-01');
        $this->get('/m/scan?code=ENTAH')->assertOk()->assertSee('Kode tidak dikenal');

        $this->getJson('/m/catalog')->assertOk()
            ->assertJsonPath('warehouse_id', $this->warehouse->id)
            ->assertJsonFragment(['sku' => 'KAOS-M', 'barcode' => '8991001'])
            ->assertJsonFragment(['code' => 'A01-01-01']);
        $this->getJson('/m/session')->assertOk()->assertJsonStructure(['csrf', 'user', 'warehouse_id', 'app' => ['versionCode', 'versionName', 'url']]);
    }

    public function test_download_page_links_the_latest_apk(): void
    {
        $this->get('/aplikasi-android')->assertOk()
            ->assertSee('releases/download/android-v', false)
            ->assertSee('Download APK')
            ->assertSee('<svg', false);
        $this->get('/dashboard')->assertSee(route('mobile.app'));
    }

    public function test_menus_follow_each_role_permission(): void
    {
        $packer = $this->staff(['mobile.access', 'sales.fulfill']);
        $this->actingAs($packer)->get('/m')->assertOk()->assertSee('QC Kemas')->assertSee('Serah Terima')->assertDontSee('Penerimaan')->assertDontSee('Stock Opname');
        $this->get('/m/receipts')->assertForbidden();
        $this->get('/m/qc')->assertOk();

        $this->actingAs($this->staff(['sales.fulfill']))->get('/m')->assertForbidden();
        $this->get('/aplikasi-android')->assertForbidden();
    }

    public function test_app_login_requires_android_permission(): void
    {
        $password = 'rahasia123';
        $without = User::factory()->create(['password' => bcrypt($password)]);
        $with = User::factory()->create(['password' => bcrypt($password)]);
        $with->givePermissionTo('mobile.access');
        auth()->logout();

        $this->withHeader('User-Agent', self::APP_UA)
            ->post('/login', ['login' => $without->username, 'password' => $password])
            ->assertSessionHasErrors();
        $this->assertGuest();

        $this->withHeader('User-Agent', self::APP_UA)
            ->post('/login', ['login' => $with->username, 'password' => $password])
            ->assertRedirect(route('mobile.home'));

        auth()->logout();
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->post('/login', ['login' => $without->username, 'password' => $password])
            ->assertRedirect(route('dashboard'));
    }

    public function test_qc_starts_from_the_receipt_then_checks_items(): void
    {
        $order = $this->order(['tracking_number' => 'RESI-QC']);

        $this->getJson('/m/qc/queue')->assertOk()->assertJsonPath('orders.0.tracking', 'RESI-QC');
        $this->getJson('/m/qc/find?code=TIDAK-ADA')->assertNotFound();
        $this->getJson('/m/qc/find?code=RESI-QC')->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.lines.0.sku', 'KAOS-M')
            ->assertJsonPath('order.lines.0.qty', 2);

        $this->postJson("/m/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 1]])->assertUnprocessable();
        $this->assertSame('picking', $order->fresh()->status);

        $this->post("/m/qc/{$order->id}", [
            'scanned' => [$this->kaosM->id => 2],
            'photo' => UploadedFile::fake()->image('paket.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('order', $order->number);

        $order->refresh();
        $this->assertSame('packed', $order->status);
        $this->assertNotNull($order->packing_photo_path);
        Storage::disk('public')->assertExists($order->packing_photo_path);

        // Antrean offline yang terkirim dua kali tidak dianggap gagal.
        $this->postJson("/m/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2]])->assertOk()->assertJsonPath('duplicate', true);
        $this->getJson('/m/qc/find?code=RESI-QC')->assertUnprocessable();
    }

    public function test_handover_scan_and_courier_signature(): void
    {
        $spx = Courier::where('code', 'SPX')->firstOrFail();
        $order = $this->order(['tracking_number' => 'SPX-1', 'courier_id' => $spx->id]);
        app(\App\Services\SalesOrderService::class)->pack($order);

        $this->get('/m/handover')->assertOk()->assertSee($spx->name);
        $this->postJson('/m/handover/scan', ['code' => 'SPX-1'])->assertOk()->assertJsonPath('order.number', $order->number);
        $this->postJson('/m/handover/scan', ['code' => 'SPX-1'])->assertUnprocessable()->assertJsonPath('duplicate', true);

        $handover = ShipmentHandover::firstOrFail();
        $this->get("/m/handover/{$handover->id}")->assertOk()->assertSee('SPX-1');

        $png = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('ttd.png', 20, 10)->getContent());
        $this->postJson("/m/handover/{$handover->id}/sign", ['receiver_name' => 'Andi SPX', 'signature' => $png])
            ->assertOk()
            ->assertJsonPath('manifest.receiver', 'Andi SPX')
            ->assertJsonPath('manifest.orders.0.tracking', 'SPX-1');

        $handover->refresh();
        $this->assertNotNull($handover->signed_at);
        Storage::disk('public')->assertExists($handover->receiver_signature_path);
        $this->postJson("/m/handover/{$handover->id}/sign", ['receiver_name' => 'X', 'signature' => 'data:image/png;base64,bukan-gambar'])->assertUnprocessable();
    }

    public function test_receipt_can_be_received_without_a_bin(): void
    {
        $supplier = Supplier::create(['code' => 'SUP1', 'name' => 'PT Kain', 'is_active' => true]);

        $this->post('/m/receipts', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'action' => 'receive',
            'attachment' => UploadedFile::fake()->image('sj.jpg'),
            'items' => [
                ['product_variant_id' => $this->kaosL->id, 'qty_received' => 5, 'qty_damaged' => 0, 'warehouse_location_id' => ''],
                ['product_variant_id' => $this->kaosM->id, 'qty_received' => 3, 'qty_damaged' => 1, 'warehouse_location_id' => $this->bin->id],
            ],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonCount(1, 'putaway')
            ->assertJsonPath('putaway.0.location', 'A01-01-01');

        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame('received', $receipt->status);
        $this->assertSame(25, (int) Stock::where('product_variant_id', $this->kaosL->id)->whereNull('warehouse_location_id')->value('qty'));
        $this->assertSame(23, (int) Stock::where('product_variant_id', $this->kaosM->id)->where('warehouse_location_id', $this->bin->id)->value('qty'));
        $this->get("/m/receipts/{$receipt->id}")->assertOk()->assertSee('Tanpa bin');

        // Staf tanpa hak "terima" hanya bisa menyimpan draft; tidak ada draft setengah jadi saat ditolak.
        $staff = $this->staff(['mobile.access', 'receipts.create']);
        $payload = ['supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'items' => [['product_variant_id' => $this->kaosL->id, 'qty_received' => 1]]];
        $this->actingAs($staff)->postJson('/m/receipts', [...$payload, 'action' => 'receive'])->assertForbidden();
        $this->assertSame(1, GoodsReceipt::count());
        $this->postJson('/m/receipts', [...$payload, 'action' => 'save'])->assertOk()->assertJsonPath('received', false);
        $this->assertSame('draft', GoodsReceipt::latest('id')->value('status'));
    }

    public function test_offline_resend_with_same_key_does_not_receive_twice(): void
    {
        $supplier = Supplier::create(['code' => 'SUP1', 'name' => 'PT Kain', 'is_active' => true]);
        $payload = [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->kaosL->id, 'qty_received' => 5, 'qty_damaged' => 0]],
        ];

        // Jawaban pertama tidak sampai ke HP, antrean offline mengirim ulang dengan kunci yang sama.
        $first = $this->postJson('/m/receipts', $payload, ['X-Idempotency-Key' => 'hp-1'])->assertOk()->json('number');
        $this->postJson('/m/receipts', $payload, ['X-Idempotency-Key' => 'hp-1'])->assertOk()
            ->assertJsonPath('number', $first)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(25, (int) Stock::where('product_variant_id', $this->kaosL->id)->whereNull('warehouse_location_id')->value('qty'));

        // Kunci lain = aksi baru; kunci sama dari akun lain tidak memakai jawaban akun ini.
        $this->postJson('/m/receipts', $payload, ['X-Idempotency-Key' => 'hp-2'])->assertOk();
        $this->actingAs($this->staff(['mobile.access', 'receipts.create', 'receipts.receive']))
            ->postJson('/m/receipts', $payload, ['X-Idempotency-Key' => 'hp-1'])->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->assertSame(3, GoodsReceipt::count());

        // Stock check & retur juga terlindungi.
        $this->actingAs($this->admin);
        $check = ['reason' => 'lost', 'items' => [['product_variant_id' => $this->kaosM->id, 'warehouse_location_id' => $this->bin->id, 'qty' => -1]]];
        $this->postJson('/m/stock-check', $check, ['X-Idempotency-Key' => 'hp-3'])->assertOk();
        $this->postJson('/m/stock-check', $check, ['X-Idempotency-Key' => 'hp-3'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, StockAdjustment::count());
    }

    public function test_two_counters_can_save_the_same_opname_sheet(): void
    {
        $this->post(route('inventory.counts.store'), [
            'warehouse_id' => $this->warehouse->id, 'scope' => 'all', 'count_date' => now()->toDateString(),
        ])->assertRedirect();
        $count = StockCount::firstOrFail();
        $lines = collect($this->getJson("/m/opname/{$count->id}/lines")->assertOk()->json('lines'));
        $this->get("/m/opname/{$count->id}")->assertOk();

        $m = $lines->firstWhere('sku', 'KAOS-M');
        $l = $lines->firstWhere('sku', 'KAOS-L');

        // Petugas A hanya mengirim baris rak A01, petugas B hanya baris tanpa bin.
        $this->postJson("/m/opname/{$count->id}", ['lines' => [['id' => $m['id'], 'counted_qty' => 19]]])->assertOk();
        $this->postJson("/m/opname/{$count->id}", ['lines' => [
            ['id' => $l['id'], 'counted_qty' => 20],
            ['product_variant_id' => $this->kaosL->id, 'warehouse_location_id' => $this->bin->id, 'counted_qty' => 2],
        ]])->assertOk();

        $items = $count->items()->get();
        $this->assertCount(3, $items);
        $this->assertSame(19, $items->firstWhere('id', $m['id'])->counted_qty);
        $this->assertSame(20, $items->firstWhere('id', $l['id'])->counted_qty);

        // Mengirim ulang baris baru (antrean offline) tidak membuat baris dobel.
        $this->postJson("/m/opname/{$count->id}", ['lines' => [
            ['product_variant_id' => $this->kaosL->id, 'warehouse_location_id' => $this->bin->id, 'counted_qty' => 3],
        ]])->assertOk();
        $this->assertSame(3, $count->items()->count());
        $this->assertSame(3, $count->items()->where('warehouse_location_id', $this->bin->id)->where('product_variant_id', $this->kaosL->id)->value('counted_qty'));

        $this->actingAs($this->staff(['mobile.access', 'opname.create']))->postJson("/m/opname/{$count->id}", ['action' => 'post', 'lines' => [['id' => $m['id'], 'counted_qty' => 19]]])->assertForbidden();
    }

    public function test_stock_check_submits_adjustment_for_approval(): void
    {
        $this->getJson('/m/stock-check/bin?code=A01-01-01')->assertOk()
            ->assertJsonPath('location.id', $this->bin->id)
            ->assertJsonPath('items.0.sku', 'KAOS-M')
            ->assertJsonPath('items.0.system_qty', 20);
        $this->getJson('/m/stock-check/bin?code=Z99')->assertNotFound();

        $this->postJson('/m/stock-check', [
            'reason' => 'lost',
            'reference' => 'Cek rak A01-01-01',
            'items' => [['product_variant_id' => $this->kaosM->id, 'warehouse_location_id' => $this->bin->id, 'qty' => -2]],
        ])->assertOk();

        $adjustment = StockAdjustment::firstOrFail();
        $this->assertSame('pending', $adjustment->status);
        $this->assertSame(20, (int) Stock::where('product_variant_id', $this->kaosM->id)->where('warehouse_location_id', $this->bin->id)->value('qty'));
    }

    public function test_return_from_order_with_condition_and_photo(): void
    {
        $order = $this->order(['tracking_number' => 'RESI-RT']);
        app(\App\Services\SalesOrderService::class)->pack($order);
        $order->update(['status' => 'shipped', 'shipped_at' => now()]);

        $item = $order->items()->first();
        $this->post('/m/returns', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'tracking_number' => 'RESI-RT',
            'action' => 'receive',
            'items' => [[
                'sales_order_item_id' => $item->id, 'qty_good' => 1, 'qty_damaged' => 0, 'qty_missing' => 0,
                'reason' => 'changed_mind', 'warehouse_location_id' => $this->bin->id,
                'photo' => UploadedFile::fake()->image('retur.jpg'),
            ]],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('received', true);

        $return = SalesReturn::firstOrFail();
        $this->assertSame('received', $return->status);
        $this->assertNotNull($return->items()->value('photo_path'));
    }
}
