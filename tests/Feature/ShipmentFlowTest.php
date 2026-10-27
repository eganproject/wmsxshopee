<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\ShipmentHandover;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ShopeeShop $shop;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private Bundle $bundle;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['shopee.document_poll_ms' => 0]);
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'address' => 'Jl. Gudang 1', 'city' => 'Jakarta', 'pic_name' => 'Rina', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->kaosL = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);
        $this->bundle = Bundle::create(['sku' => 'PAKET-2', 'name' => 'Paket M+L', 'barcode' => '8990002', 'price' => 1, 'is_active' => true]);
        $this->bundle->items()->createMany([
            ['product_variant_id' => $this->kaosM->id, 'qty' => 1],
            ['product_variant_id' => $this->kaosL->id, 'qty' => 1],
        ]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->kaosM->id, 'to_location_id' => null, 'qty' => 20],
            ['product_variant_id' => $this->kaosL->id, 'to_location_id' => null, 'qty' => 20],
        ]);

        $this->shop = ShopeeShop::create([
            'environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
        ]);

        $this->admin = User::where('username', 'superadmin')->first();
        $this->actingAs($this->admin);
    }

    /** Pesanan terkonfirmasi (stok dicadangkan). */
    private function order(array $attributes = [], ?array $lines = null): SalesOrder
    {
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'items' => $lines ?? [['product_variant_id' => $this->kaosM->id, 'qty' => 2, 'price' => 75000]],
            'action' => 'confirm',
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->firstOrFail();
        $order->update($attributes);

        return $order->fresh();
    }

    private function shopeeOrder(string $sn, array $attributes = []): SalesOrder
    {
        return $this->order(['shopee_shop_id' => $this->shop->id, 'external_number' => $sn, 'shopee_order_status' => 'READY_TO_SHIP', 'shopee_package_number' => 'PKG-'.$sn, 'channel_name' => 'Shopee · Toko Uji', ...$attributes]);
    }

    private function pick(SalesOrder $order): void
    {
        $this->post("/sales/orders/{$order->id}/pick", ['picks' => $order->items->mapWithKeys(fn ($item) => [
            $item->id => [['warehouse_location_id' => '', 'qty' => $item->qty]],
        ])->all()])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function shippingParameter(array $infoNeeded): array
    {
        return ['error' => '', 'response' => [
            'info_needed' => $infoNeeded,
            'pickup' => ['address_list' => [
                ['address_id' => 1, 'city' => 'Bekasi', 'address' => 'Jl. Lama 5', 'address_flag' => ['default_address'], 'time_slot_list' => [
                    ['date' => now()->addDay()->startOfDay()->getTimestamp(), 'time_text' => '09:00-12:00', 'pickup_time_id' => 'slot-a1'],
                ]],
                ['address_id' => 2, 'city' => 'Jakarta', 'address' => 'Jl. Gudang 1', 'address_flag' => ['pickup_address'], 'time_slot_list' => [
                    ['date' => now()->addDay()->startOfDay()->getTimestamp(), 'time_text' => '09:00-12:00', 'pickup_time_id' => 'slot-b1'],
                    ['date' => now()->addDay()->startOfDay()->getTimestamp(), 'time_text' => '13:00-17:00', 'pickup_time_id' => 'slot-b2', 'flags' => ['recommended']],
                ]],
            ]],
            'dropoff' => ['branch_list' => [
                ['branch_id' => 77, 'city' => 'Jakarta', 'address' => 'SPX Hub Kemang'],
            ]],
        ]];
    }

    private function fakeLogistics(array $infoNeeded, array $more = []): void
    {
        // Isian $more menggantikan respons bawaan dengan pola yang sama.
        Http::fake([
            '*/logistics/get_shipping_parameter*' => Http::response($this->shippingParameter($infoNeeded)),
            '*/logistics/ship_order*' => Http::response(['error' => '', 'response' => []]),
            '*/logistics/get_tracking_number*' => Http::response(['error' => '', 'response' => ['tracking_number' => 'SPXID777']]),
            ...$more,
        ]);
    }

    public function test_shopee_pickup_is_arranged_with_chosen_address_and_slot(): void
    {
        $order = $this->shopeeOrder('S1', ['shopee_ship_by' => now()->addDays(2)]);
        $this->fakeLogistics(['pickup' => ['address_id', 'pickup_time_id'], 'dropoff' => []]);

        $this->get("/sales/orders/{$order->id}/shipment")->assertOk()->assertSee('Jl. Gudang 1')->assertSee('Alamat pickup')->assertSee('Kirim sebelum');

        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'pickup', 'address_id' => 2, 'pickup_time_id' => 'slot-b2'])
            ->assertSessionHasNoErrors()->assertRedirect("/sales/orders/{$order->id}");

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ship_order') && $r['order_sn'] === 'S1' && $r['package_number'] === 'PKG-S1'
            && $r['pickup'] === ['address_id' => 2, 'pickup_time_id' => 'slot-b2']);

        $order->refresh();
        $this->assertSame(['pickup', 'PROCESSED', 'SPXID777', $this->admin->id], [$order->shipping_method, $order->shopee_order_status, $order->tracking_number, $order->shipment_arranged_by]);
        $this->assertStringContainsString('13:00-17:00', $order->pickup_slot);
        $this->assertStringContainsString('Jl. Gudang 1', $order->pickup_address);
        $this->assertFalse($order->canArrangeShipment(), 'Shopee tidak menerima pengaturan ulang.');
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Cetak Resi')->assertSee('13:00-17:00');
    }

    public function test_order_list_shows_arranged_pickup_in_shipping_column(): void
    {
        $this->shopeeOrder('V1', ['shipping_method' => 'pickup', 'pickup_at' => now()->setTime(16, 0), 'shipment_arranged_at' => now()]);
        $this->shopeeOrder('V2');

        $this->get('/sales/orders')->assertOk()
            ->assertSee('Pickup diatur')->assertSee('Jemput')->assertSee('16:00')
            ->assertSee('Belum diatur');
    }

    public function test_bulk_arrange_is_only_offered_for_orders_not_yet_arranged(): void
    {
        $arranged = $this->shopeeOrder('W1', ['shipping_method' => 'pickup', 'pickup_at' => now()->setTime(16, 0), 'shipment_arranged_at' => now(), 'shopee_order_status' => 'PROCESSED']);
        // Diatur di Optima, status Shopee belum tersinkron: tetap dianggap sudah diatur.
        $stale = $this->shopeeOrder('W2', ['shipping_method' => 'pickup', 'shipment_arranged_at' => now()]);
        $open = $this->shopeeOrder('W3');
        $manual = $this->order();

        $rows = $this->get('/sales/orders')->assertOk()->viewData('orders')->getCollection()->keyBy('id');

        $this->assertFalse($rows[$arranged->id]->canBulkArrangeShipment());
        $this->assertFalse($rows[$stale->id]->canBulkArrangeShipment());
        $this->assertTrue($rows[$open->id]->canBulkArrangeShipment());
        $this->assertFalse($rows[$manual->id]->canBulkArrangeShipment(), 'Pesanan manual diatur dari halaman pesanan.');
    }

    public function test_bulk_arrange_skips_orders_already_arranged(): void
    {
        $arranged = $this->shopeeOrder('X1', ['shipping_method' => 'pickup', 'shipment_arranged_at' => now(), 'shopee_order_status' => 'PROCESSED']);
        Http::fake();

        $this->post('/sales/orders/shipment', ['method' => 'pickup', 'orders' => [$arranged->id]])
            ->assertSessionHas('info', fn ($message) => str_contains($message, 'sudah diatur') && str_contains($message, $arranged->number))
            ->assertSessionMissing('shipment_failures');

        Http::assertNothingSent();
    }

    public function test_order_list_shows_ordered_skus_and_searches_by_sku(): void
    {
        $order = $this->order(['customer_name' => 'Rani'], [
            ['product_variant_id' => $this->kaosM->id, 'qty' => 2, 'price' => 75000],
            ['product_variant_id' => $this->kaosL->id, 'qty' => 3, 'price' => 75000],
        ]);
        $other = $this->order(['customer_name' => 'Joko'], [['product_variant_id' => $this->kaosM->id, 'qty' => 1, 'price' => 75000]]);

        $this->get('/sales/orders')->assertOk()
            ->assertSeeText('Barang Dipesan')->assertSee('KAOS-L')->assertSee('×3')->assertSee('2 SKU · 5 barang');

        $found = $this->get('/sales/orders?search=KAOS-L')->assertOk()->viewData('orders')->pluck('id');
        $this->assertTrue($found->contains($order->id));
        $this->assertFalse($found->contains($other->id));
    }

    public function test_order_tab_counts_follow_active_filters(): void
    {
        $this->shopeeOrder('Y1');
        $this->order(['customer_name' => 'Sinta Unik']);

        $counts = $this->get('/sales/orders?search=Sinta+Unik')->assertOk()->viewData('counts');

        $this->assertSame(1, (int) $counts->sum());
    }

    public function test_unsplit_shopee_order_is_arranged_without_package_number(): void
    {
        $order = $this->shopeeOrder('S9', ['shopee_package_number' => null]);
        $this->fakeLogistics(['pickup' => ['address_id', 'pickup_time_id']]);

        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'pickup', 'address_id' => 2, 'pickup_time_id' => 'slot-b2'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ship_order') && $r['order_sn'] === 'S9' && ! isset($r['package_number']));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'logistics/') && isset($r['package_number']));
        $this->assertSame('PROCESSED', $order->fresh()->shopee_order_status);
    }

    public function test_stale_package_number_is_dropped_when_shopee_says_order_is_unsplit(): void
    {
        $order = $this->shopeeOrder('S8');
        $unsplit = ['error' => 'logistics.ship_order_not_need_pacakge_number', 'message' => "Please don't request with package_number for this unsplit order."];
        Http::fake(fn (Request $r) => match (true) {
            isset($r['package_number']) => Http::response($unsplit),
            str_contains($r->url(), 'get_shipping_parameter') => Http::response($this->shippingParameter(['pickup' => ['address_id', 'pickup_time_id']])),
            str_contains($r->url(), 'get_tracking_number') => Http::response(['error' => '', 'response' => ['tracking_number' => 'SPXID888']]),
            default => Http::response(['error' => '', 'response' => []]),
        });

        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'pickup', 'address_id' => 2, 'pickup_time_id' => 'slot-b2'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ship_order') && ! isset($r['package_number']));
        $order->refresh();
        $this->assertNull($order->shopee_package_number);
        $this->assertSame(['PROCESSED', 'SPXID888'], [$order->shopee_order_status, $order->tracking_number]);
    }

    public function test_shopee_dropoff_requires_branch_when_courier_asks_for_it(): void
    {
        $order = $this->shopeeOrder('S2');
        $this->fakeLogistics(['dropoff' => ['branch_id']]);

        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'dropoff'])->assertSessionHasErrors('branch_id');
        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'pickup'])->assertSessionHasErrors('method');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ship_order'));

        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'dropoff', 'branch_id' => 77])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ship_order') && $r['dropoff'] === ['branch_id' => 77]);
        $this->assertSame(['dropoff', 'SPX Hub Kemang, Jakarta'], [$order->fresh()->shipping_method, $order->fresh()->dropoff_point]);
    }

    public function test_bulk_pickup_uses_pickup_address_and_recommended_slot(): void
    {
        $a = $this->shopeeOrder('B1');
        $b = $this->shopeeOrder('B2');
        $manual = $this->order();
        $this->fakeLogistics(['pickup' => ['address_id', 'pickup_time_id'], 'dropoff' => ['branch_id']]);

        $this->post('/sales/orders/shipment', ['method' => 'pickup', 'orders' => [$a->id, $b->id, $manual->id]])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 pesanan diatur Pickup') && str_contains($message, $manual->number));

        Http::assertSentCount(6); // get_shipping_parameter + ship_order + get_tracking_number per pesanan
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ship_order') && $r['order_sn'] === 'B2' && $r['pickup'] === ['address_id' => 2, 'pickup_time_id' => 'slot-b2']);
        $this->assertSame('pickup', $a->fresh()->shipping_method);
        $this->assertNull($manual->fresh()->shipping_method);

        // Drop off yang butuh cabang tidak bisa diatur massal.
        $c = $this->shopeeOrder('B3');
        $this->post('/sales/orders/shipment', ['method' => 'dropoff', 'orders' => [$c->id]])->assertSessionHas('shipment_failures', fn ($failures) => str_contains($failures[$c->number], 'cabang'));
    }

    public function test_bulk_pickup_reports_failed_orders_and_arranges_the_rest(): void
    {
        $orders = collect(['P1', 'P2', 'P3', 'P4'])->map(fn ($sn) => $this->shopeeOrder($sn));
        $this->fakeLogistics(['pickup' => ['address_id', 'pickup_time_id']], [
            '*/logistics/ship_order*' => fn (Request $r) => $r['order_sn'] === 'P3'
                ? Http::response(['error' => 'logistics.ship_order_invalid', 'message' => 'Order status invalid'])
                : Http::response(['error' => '', 'response' => []]),
        ]);

        $this->post('/sales/orders/shipment', ['method' => 'pickup', 'orders' => $orders->pluck('id')->all()])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '3 pesanan diatur Pickup'))
            ->assertSessionHas('shipment_failures', fn ($failures) => array_keys($failures) === [$orders[2]->number] && str_contains($failures[$orders[2]->number], 'Order status invalid'));

        $this->assertSame(['pickup', 'SPXID777', 'PROCESSED'], [$orders[3]->fresh()->shipping_method, $orders[3]->fresh()->tracking_number, $orders[3]->fresh()->shopee_order_status]);
        $this->assertNull($orders[2]->fresh()->shipment_arranged_at);
        Http::assertSentCount(11); // 4 parameter + 4 atur + 3 resi
    }

    public function test_bulk_pickup_retries_an_order_that_hit_the_rate_limit(): void
    {
        $a = $this->shopeeOrder('R1');
        $b = $this->shopeeOrder('R2');
        $limited = 0;
        $this->fakeLogistics(['pickup' => ['address_id', 'pickup_time_id']], [
            '*/logistics/ship_order*' => function (Request $r) use (&$limited) {
                if ($r['order_sn'] === 'R2' && $limited++ === 0) {
                    return Http::response(['error' => 'error_rate_limit', 'message' => 'Too many requests'], 429);
                }

                return Http::response(['error' => '', 'response' => []]);
            },
        ]);
        Sleep::fake();

        $this->post('/sales/orders/shipment', ['method' => 'pickup', 'orders' => [$a->id, $b->id]])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 pesanan diatur Pickup'))
            ->assertSessionMissing('shipment_failures');

        $this->assertSame(2, $limited);
        $this->assertNotNull($b->fresh()->shipment_arranged_at);
    }

    public function test_shopee_label_is_created_polled_and_downloaded_as_pdf(): void
    {
        $order = $this->shopeeOrder('L1', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPXID001']);
        $results = 0;
        Http::fake([
            '*/logistics/get_shipping_document_parameter*' => Http::response(['error' => '', 'response' => ['result_list' => [
                ['order_sn' => 'L1', 'suggest_shipping_document_type' => 'NORMAL_AIR_WAYBILL', 'selectable_shipping_document_type' => ['NORMAL_AIR_WAYBILL', 'THERMAL_AIR_WAYBILL']],
            ]]]),
            '*/logistics/create_shipping_document*' => Http::response(['error' => '', 'response' => ['result_list' => [['order_sn' => 'L1']]]]),
            '*/logistics/get_shipping_document_result*' => function () use (&$results) {
                $results++;

                return Http::response(['error' => '', 'response' => ['result_list' => [['order_sn' => 'L1', 'status' => $results < 2 ? 'PROCESSING' : 'READY']]]]);
            },
            '*/logistics/download_shipping_document*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->get("/sales/orders/{$order->id}/shipping-label")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4 label', false);

        $this->assertSame(2, $results);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_shipping_document') && $r['order_list'][0]['shipping_document_type'] === 'THERMAL_AIR_WAYBILL' && $r['order_list'][0]['tracking_number'] === 'SPXID001');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'download_shipping_document') && $r['shipping_document_type'] === 'THERMAL_AIR_WAYBILL');
        $this->assertNotNull($order->fresh()->shipping_label_printed_at);
    }

    public function test_shopee_label_error_is_shown(): void
    {
        $order = $this->shopeeOrder('L2', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'X']);
        Http::fake(['*/logistics/get_shipping_document_parameter*' => Http::response(['error' => '', 'response' => ['result_list' => [
            ['order_sn' => 'L2', 'fail_error' => 'logistics.package_not_ready', 'fail_message' => 'Paket belum siap'],
        ]]])]);

        $this->from("/sales/orders/{$order->id}")->get("/sales/orders/{$order->id}/shipping-label")
            ->assertRedirect("/sales/orders/{$order->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'Paket belum siap'));
    }

    public function test_shopee_batch_all_failed_shows_reason_from_result_list(): void
    {
        $order = $this->shopeeOrder('L3', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'X']);
        Http::fake(['*/logistics/get_shipping_document_parameter*' => Http::response([
            'error' => 'common.batch_api_all_failed', 'message' => 'All failed, please check result_list for detail',
            'response' => ['result_list' => [['order_sn' => 'L3', 'fail_error' => 'logistics.package_can_not_print', 'fail_message' => 'Package can not print now.']]],
        ])]);

        $this->from("/sales/orders/{$order->id}")->get("/sales/orders/{$order->id}/shipping-label")
            ->assertRedirect("/sales/orders/{$order->id}")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'L3: Package can not print now.') && ! str_contains($m, 'All failed'));
    }

    public function test_shopee_label_waits_for_tracking_number(): void
    {
        $order = $this->shopeeOrder('L4', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => null]);
        Http::fake(['*/logistics/get_tracking_number*' => Http::response(['error' => '', 'response' => ['tracking_number' => '']])]);

        $this->from("/sales/orders/{$order->id}")->get("/sales/orders/{$order->id}/shipping-label")
            ->assertRedirect("/sales/orders/{$order->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'belum terbit'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'shipping_document'));
    }

    public function test_mixed_shopee_and_manual_labels_are_printed_together(): void
    {
        $shopee = $this->shopeeOrder('M1', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPXID001']);
        $manual = $this->order(['shipping_method' => 'dropoff', 'dropoff_point' => 'JNE Kemang', 'tracking_number' => 'JNE777']);
        $draft = $this->order();
        $draft->update(['status' => 'draft']);
        Http::fake([
            '*/logistics/get_shipping_document_parameter*' => Http::response(['error' => '', 'response' => ['result_list' => [['order_sn' => 'M1', 'suggest_shipping_document_type' => 'THERMAL_AIR_WAYBILL']]]]),
            '*/logistics/create_shipping_document*' => Http::response(['error' => '', 'response' => ['result_list' => [['order_sn' => 'M1']]]]),
            '*/logistics/get_shipping_document_result*' => Http::response(['error' => '', 'response' => ['result_list' => [['order_sn' => 'M1', 'status' => 'READY']]]]),
            '*/logistics/download_shipping_document*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $page = $this->get('/sales/orders/shipping-labels?'.http_build_query(['orders' => [$shopee->id, $manual->id, $draft->id]]))
            ->assertOk()->assertSee('Cetak Resi Gabungan')->assertSee('Shopee · Toko Uji')->assertSee('Pesanan manual')
            ->assertSee('Dilewati karena pengiriman belum diatur: '.$draft->number);
        $parts = $page->viewData('parts');
        $this->assertCount(2, $parts);

        $this->get($parts[0]['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4 label', false);
        $this->getJson($parts[1]['url'])->assertOk()
            ->assertJsonPath('labels.0.tracking', 'JNE777')->assertJsonPath('labels.0.info', 'JNE Kemang')->assertJsonPath('labels.0.items.0.sku', 'KAOS-M')
            ->assertJsonStructure(['labels' => [['barcode' => ['width', 'bars']]]]);
        $this->assertNotNull($manual->fresh()->shipping_label_printed_at);

        // Bagian yang gagal dibalas alasannya supaya ditampilkan di halaman cetak.
        $this->getJson(route('sales.orders.shipping-labels.part', ['orders' => [$draft->id]]))->assertStatus(422)->assertJsonPath('message', 'Label resi tersedia setelah pengiriman diatur.');
        $this->getJson(route('sales.orders.shipping-labels.part', ['orders' => [$shopee->id, $manual->id]]))->assertStatus(422);
    }

    public function test_shopee_packages_that_cannot_download_together_are_printed_one_by_one(): void
    {
        $a = $this->shopeeOrder('T1', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPX1']);
        $b = $this->shopeeOrder('T2', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPX2']);
        $rows = fn (Request $r, array $extra = []) => collect($r['order_list'])->map(fn ($row) => ['order_sn' => $row['order_sn'], ...$extra])->all();
        Http::fake([
            '*/logistics/get_shipping_document_parameter*' => fn (Request $r) => Http::response(['error' => '', 'response' => ['result_list' => $rows($r, ['suggest_shipping_document_type' => 'THERMAL_AIR_WAYBILL'])]]),
            '*/logistics/create_shipping_document*' => fn (Request $r) => Http::response(['error' => '', 'response' => ['result_list' => $rows($r)]]),
            '*/logistics/get_shipping_document_result*' => fn (Request $r) => Http::response(['error' => '', 'response' => ['result_list' => $rows($r, ['status' => 'READY'])]]),
            '*/logistics/download_shipping_document*' => fn (Request $r) => count($r['order_list']) > 1
                ? Http::response(['error' => 'logistics.packages_can_not_download_together', 'message' => 'Packages can not download together.'], 200)
                : Http::response('%PDF-1.4 '.$r['order_list'][0]['order_sn'], 200, ['Content-Type' => 'application/pdf']),
        ]);

        $page = $this->get('/sales/orders/shipping-labels?'.http_build_query(['orders' => [$a->id, $b->id]]))
            ->assertOk()->assertSee('Cetak Resi Gabungan');
        $parts = $page->viewData('parts');
        $this->assertCount(1, $parts);

        $this->getJson($parts[0]['url'])->assertOk()->assertJsonCount(2, 'pdfs')
            ->assertJsonPath('pdfs.0', base64_encode('%PDF-1.4 T1'))->assertJsonPath('pdfs.1', base64_encode('%PDF-1.4 T2'));
        $this->assertNotNull($b->fresh()->shipping_label_printed_at);
    }

    public function test_shopee_labels_are_split_per_courier_and_method_in_batch(): void
    {
        $a = $this->shopeeOrder('U1', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPX1', 'shipping_method' => 'pickup']);
        $b = $this->shopeeOrder('U2', ['shopee_order_status' => 'PROCESSED', 'tracking_number' => 'SPX2', 'shipping_method' => 'dropoff']);

        $parts = $this->get('/sales/orders/shipping-labels?'.http_build_query(['orders' => [$a->id, $b->id]]))->assertOk()->viewData('parts');

        $this->assertCount(2, $parts);
        $this->assertSame([$a->number], $parts[0]['numbers']);
        $this->assertSame([$b->number], $parts[1]['numbers']);
    }

    public function test_manual_order_records_pickup_or_dropoff_and_prints_own_label(): void
    {
        $order = $this->order();
        $courier = Courier::first();

        $this->get("/sales/orders/{$order->id}/shipment")->assertOk()->assertSee('Jl. Gudang 1');
        $this->post("/sales/orders/{$order->id}/shipment", ['shipping_method' => 'dropoff'])->assertSessionHasErrors('dropoff_point');
        $this->post("/sales/orders/{$order->id}/shipment", [
            'shipping_method' => 'pickup', 'pickup_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'pickup_address' => 'Jl. Gudang 1, Jakarta', 'courier_id' => $courier->id, 'tracking_number' => 'JNE555',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['pickup', '10:00', $courier->id, 'JNE555'], [$order->shipping_method, $order->pickup_at->format('H:i'), $order->courier_id, $order->tracking_number]);
        $this->assertTrue($order->canArrangeShipment(), 'Pesanan manual boleh diubah sebelum dikirim.');

        $this->get("/sales/orders/{$order->id}/shipping-label")->assertOk()->assertSee('JNE555')->assertSee('Gudang Jakarta')->assertSee('KAOS-M')->assertSee('<svg', false);
        $this->get('/sales/orders?shipment=pickup')->assertOk()->assertSee($order->number);
        $this->get('/sales/orders?shipment=unarranged')->assertOk()->assertDontSee($order->number);
    }

    public function test_label_print_is_counted_and_shown_in_order_list(): void
    {
        $order = $this->order(['shipping_method' => 'dropoff', 'dropoff_point' => 'JNE Kemang', 'shipment_arranged_at' => now(), 'tracking_number' => 'JNE900']);
        $waiting = $this->order(['shipping_method' => 'dropoff', 'dropoff_point' => 'JNE Kemang', 'shipment_arranged_at' => now(), 'tracking_number' => 'JNE901']);

        $this->get('/sales/orders?label=unprinted')->assertOk()->assertSee($order->number)->assertSee($waiting->number)->assertSee('Belum cetak resi');

        $this->get("/sales/orders/{$order->id}/shipping-label")->assertOk();
        $this->assertSame(1, $order->fresh()->shipping_label_print_count);

        $this->get("/sales/orders/{$order->id}/shipping-label")->assertOk();
        $order->refresh();
        $this->assertSame(2, $order->shipping_label_print_count);

        $this->get('/sales/orders?label=printed')->assertOk()->assertSee($order->number)->assertDontSee($waiting->number)
            ->assertSee('Resi dicetak '.$order->shipping_label_printed_at->format('d/m H:i').' · 2x');
        $this->get('/sales/orders?label=unprinted')->assertOk()->assertSee($waiting->number)->assertDontSee($order->number);
    }

    public function test_qc_scan_must_match_before_packing(): void
    {
        $order = $this->order(['tracking_number' => 'JNE900'], [['product_variant_id' => $this->kaosM->id, 'qty' => 2, 'price' => 1], ['bundle_id' => $this->bundle->id, 'qty' => 1, 'price' => 1]]);
        $this->pick($order);

        $this->get('/sales/qc')->assertOk()->assertSee($order->number);
        $this->post('/sales/qc', ['code' => 'TIDAK-ADA'])->assertSessionHas('error');
        $this->post('/sales/qc', ['code' => 'JNE900'])->assertRedirect("/sales/qc/{$order->id}");
        $this->get("/sales/qc/{$order->id}")->assertOk()->assertSee('8991001')->assertSee('8990002');

        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2, $this->kaosL->id => 1]])->assertSessionHasErrors('scan');
        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 3, $this->kaosL->id => 1, 999 => 1]])->assertSessionHasErrors('scan');
        $this->assertSame('picking', $order->fresh()->status);

        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 3, $this->kaosL->id => 1]])->assertSessionHasNoErrors();
        $this->assertSame(['packed', $this->admin->id], [$order->fresh()->status, $order->fresh()->qc_checked_by]);
    }

    public function test_handover_scan_ships_orders_and_prints_manifest(): void
    {
        $spx = Courier::where('code', 'SPX')->firstOrFail();
        $jne = Courier::where('code', 'JNE')->firstOrFail();
        $a = $this->order(['tracking_number' => 'SPX-A', 'courier_id' => $spx->id]);
        $b = $this->order(['tracking_number' => 'JNE-B', 'courier_id' => $jne->id]);
        $notPacked = $this->order(['tracking_number' => 'SPX-C', 'courier_id' => $spx->id]);
        $shopee = $this->shopeeOrder('H1', ['tracking_number' => 'SPX-S', 'courier_id' => $spx->id]);
        foreach ([$a, $b, $shopee] as $order) {
            $this->pick($order);
            $this->post("/sales/orders/{$order->id}/pack")->assertSessionHasNoErrors();
        }

        $this->get('/sales/handovers')->assertOk()->assertSeeText('Scan Resi / No. Pesanan');

        $this->post('/sales/handovers/scan', ['code' => 'SPX-A'])->assertSessionHas('scan_ok');
        $this->post('/sales/handovers/scan', ['code' => $b->number])->assertSessionHas('scan_ok');
        $this->post('/sales/handovers/scan', ['code' => 'SPX-A'])->assertSessionHas('scan_error', fn ($m) => str_contains($m, 'sudah dikirim'));
        $this->post('/sales/handovers/scan', ['code' => 'SPX-C'])->assertSessionHas('scan_error', fn ($m) => str_contains($m, 'QC'));
        $this->post('/sales/handovers/scan', ['code' => 'SPX-S'])->assertSessionHas('scan_error', fn ($m) => str_contains($m, 'belum diatur di Shopee'));
        $this->post('/sales/handovers/scan', ['code' => 'NOPE'])->assertSessionHas('scan_error', fn ($m) => str_contains($m, 'tidak ditemukan'));

        // Satu manifest per gudang & kurir per hari, dibuat otomatis.
        $this->assertSame(2, ShipmentHandover::count());
        $spxManifest = ShipmentHandover::where('courier_id', $spx->id)->firstOrFail();
        $a->refresh();
        $this->assertSame(['shipped', $spxManifest->id, $this->admin->id], [$a->status, $a->shipment_handover_id, $a->shipped_by]);
        $this->assertSame('shipped', $b->fresh()->status);
        $this->assertNotSame($spxManifest->id, $b->fresh()->shipment_handover_id);
        $this->assertSame(20 - 4, (int) Stock::where('product_variant_id', $this->kaosM->id)->sum('qty'), 'Hanya paket yang diserahkan yang keluar dari stok.');

        $this->get("/sales/handovers/{$spxManifest->id}")->assertOk()->assertSee('SPX-A')->assertDontSee('JNE-B');
        $this->get("/sales/handovers/{$spxManifest->id}/manifest")->assertOk()->assertSee('SPX-A')->assertDontSee('JNE-B');
        $this->get('/sales/handovers')->assertOk()->assertSee($spxManifest->number)->assertSee('SPX-A');

        // Hari berikutnya: manifest kemarin tertutup, scan baru masuk manifest baru.
        $this->travel(1)->days();
        $shopee->update(['shopee_order_status' => 'PROCESSED']);
        $this->post('/sales/handovers/scan', ['code' => 'SPX-S'])->assertSessionHas('scan_ok');
        $this->assertSame('closed', $spxManifest->fresh()->status);
        $this->assertNotNull($spxManifest->fresh()->closed_at);
        $this->assertNotSame($spxManifest->id, $shopee->fresh()->shipment_handover_id);
        $this->assertSame(3, ShipmentHandover::count());
    }

    /** Tombol Kirim manual menahan paket marketplace yang diajukan batal / belum diatur, sama seperti serah terima. */
    public function test_manual_ship_respects_marketplace_cancel_and_arrangement(): void
    {
        $spx = Courier::where('code', 'SPX')->firstOrFail();
        $shopee = $this->shopeeOrder('M1', ['tracking_number' => 'SPX-M', 'courier_id' => $spx->id]);
        $this->pick($shopee);
        $this->post("/sales/orders/{$shopee->id}/pack")->assertSessionHasNoErrors();
        $ship = fn () => $this->post("/sales/orders/{$shopee->id}/ship", ['courier_id' => $spx->id, 'tracking_number' => 'SPX-M']);

        $ship()->assertSessionHasErrors(['code' => "{$shopee->number}: pengiriman belum diatur di Shopee. Atur pickup / drop off dulu."]);

        $shopee->update(['shopee_order_status' => 'IN_CANCEL']);
        $ship()->assertSessionHasErrors('code');
        $this->assertSame('packed', $shopee->fresh()->status);

        $shopee->update(['shopee_order_status' => 'PROCESSED']);
        $ship()->assertSessionHasNoErrors();
        $this->assertSame('shipped', $shopee->fresh()->status);
    }

    public function test_staff_must_use_scans_instead_of_manual_pack_and_ship(): void
    {
        $order = $this->order(['tracking_number' => 'JNE1']);
        $this->pick($order);

        $staff = User::create(['name' => 'Staff', 'username' => 'staff', 'email' => 'staff@wms.local', 'password' => 'password', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');
        $this->actingAs($staff);

        $this->post("/sales/orders/{$order->id}/pack")->assertForbidden();
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('QC Scan')->assertDontSee('Kemas tanpa Scan');
        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2]])->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/ship", ['courier_id' => Courier::first()->id, 'tracking_number' => 'JNE1'])->assertForbidden();
    }
}
