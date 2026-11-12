<?php

namespace Tests\Feature;

use App\Jobs\RunMarketplaceTask;
use App\Models\MarketplaceSyncLog;
use App\Models\MarketplaceWebhookEvent;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * User yang hanya punya akses gudang tertentu hanya melihat & mengelola toko
 * marketplace dan pesanan milik gudang itu.
 */
class ShopWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $surabaya;

    private Warehouse $jakarta;

    private ShopeeShop $shopeeSby;

    private ShopeeShop $shopeeJkt;

    private TiktokShop $tiktokSby;

    private TiktokShop $tiktokJkt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->surabaya = Warehouse::create(['code' => 'SBY01', 'name' => 'Gudang Surabaya', 'is_active' => true]);
        $this->jakarta = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);

        $this->shopeeSby = $this->shopee(1001, 'Shopee Surabaya Store', $this->surabaya);
        $this->shopeeJkt = $this->shopee(1002, 'Shopee Jakarta Store', $this->jakarta);
        $this->tiktokSby = $this->tiktok('7001', 'TikTok Surabaya Store', $this->surabaya);
        $this->tiktokJkt = $this->tiktok('7002', 'TikTok Jakarta Store', $this->jakarta);
    }

    private function shopee(int $id, string $name, Warehouse $warehouse): ShopeeShop
    {
        return ShopeeShop::create([
            'environment' => 'live', 'shop_id' => $id, 'shop_name' => $name, 'warehouse_id' => $warehouse->id, 'is_active' => true,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
            'connected_at' => now(), 'last_synced_at' => now(),
        ]);
    }

    private function tiktok(string $id, string $name, Warehouse $warehouse): TiktokShop
    {
        return TiktokShop::create([
            'shop_id' => $id, 'shop_cipher' => 'cipher-'.$id, 'shop_name' => $name, 'region' => 'ID', 'is_active' => true,
            'warehouse_id' => $warehouse->id, 'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addDays(5), 'connected_at' => now(), 'last_synced_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /** Admin dengan izin marketplace (termasuk hubungkan toko) tapi hanya gudang Surabaya. */
    private function surabayaUser(): User
    {
        $user = User::factory()->create()->assignRole('Admin')->givePermissionTo(['shopee.connect', 'tiktok.connect']);
        $user->syncWarehouseAccess(false, [$this->surabaya->id]);

        return $user->fresh();
    }

    private function order(ShopeeShop $shop, string $ref): SalesOrder
    {
        return SalesOrder::create([
            'number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $shop->warehouse_id,
            'order_date' => now()->toDateString(), 'customer_name' => 'Pembeli '.$ref, 'shopee_shop_id' => $shop->id,
            'external_number' => $ref, 'channel_name' => $shop->channelName(),
        ]);
    }

    public function test_limited_user_only_sees_shops_of_their_warehouse(): void
    {
        $this->actingAs($this->surabayaUser());

        $this->get('/marketplace/shops')->assertOk()
            ->assertSee('Shopee Surabaya Store')->assertSee('TikTok Surabaya Store')
            ->assertDontSee('Shopee Jakarta Store')->assertDontSee('TikTok Jakarta Store');

        $this->get('/marketplace/products')->assertOk()
            ->assertSee('Shopee Surabaya Store')->assertDontSee('Shopee Jakarta Store');

        $this->assertSame([$this->shopeeSby->id], ShopeeShop::pluck('id')->all());
        $this->assertSame([$this->tiktokSby->id], TiktokShop::pluck('id')->all());
    }

    public function test_unrestricted_users_still_see_every_shop(): void
    {
        foreach ([$this->superAdmin(), User::factory()->create()->assignRole('Admin')] as $user) {
            $this->actingAs($user)->get('/marketplace/shops')->assertOk()
                ->assertSee('Shopee Surabaya Store')->assertSee('Shopee Jakarta Store')
                ->assertSee('TikTok Surabaya Store')->assertSee('TikTok Jakarta Store');
        }
    }

    public function test_limited_user_cannot_manage_other_warehouse_shops(): void
    {
        Queue::fake();
        $this->actingAs($this->surabayaUser());

        $this->get("/shopee/shops/{$this->shopeeJkt->id}/mappings")->assertNotFound();
        $this->get("/tiktok/shops/{$this->tiktokJkt->id}/mappings")->assertNotFound();
        $this->put("/shopee/shops/{$this->shopeeJkt->id}", ['warehouse_id' => $this->surabaya->id, 'is_active' => '1'])->assertNotFound();
        $this->put("/tiktok/shops/{$this->tiktokJkt->id}", ['warehouse_id' => $this->surabaya->id, 'is_active' => '1'])->assertNotFound();
        $this->post("/shopee/shops/{$this->shopeeJkt->id}/sync")->assertNotFound();
        $this->post("/tiktok/shops/{$this->tiktokJkt->id}/sync")->assertNotFound();
        $this->post("/marketplace/shops/shopee/{$this->shopeeJkt->id}/sync-all")->assertNotFound();
        $this->delete("/shopee/shops/{$this->shopeeJkt->id}")->assertNotFound();
        $this->delete("/tiktok/shops/{$this->tiktokJkt->id}")->assertNotFound();

        $this->assertSame($this->jakarta->id, ShopeeShop::withoutGlobalScope('warehouse_access')->find($this->shopeeJkt->id)->warehouse_id);
        $this->assertTrue(TiktokShop::withoutGlobalScope('warehouse_access')->whereKey($this->tiktokJkt->id)->exists());

        // Toko sendiri tetap bisa dikelola.
        $this->get("/shopee/shops/{$this->shopeeSby->id}/mappings")->assertOk();
        $this->post("/shopee/shops/{$this->shopeeSby->id}/sync")->assertRedirect();
    }

    public function test_limited_user_cannot_move_shop_to_or_connect_with_other_warehouse(): void
    {
        $this->actingAs($this->surabayaUser());

        $this->put("/shopee/shops/{$this->shopeeSby->id}", ['warehouse_id' => $this->jakarta->id, 'is_active' => '1'])->assertSessionHasErrors('warehouse_id');
        $this->put("/tiktok/shops/{$this->tiktokSby->id}", ['warehouse_id' => $this->jakarta->id, 'is_active' => '1'])->assertSessionHasErrors('warehouse_id');
        $this->post('/shopee/shops/connect', ['warehouse_id' => $this->jakarta->id])->assertSessionHasErrors('warehouse_id');
        $this->post('/tiktok/shops/connect', ['warehouse_id' => $this->jakarta->id])->assertSessionHasErrors('warehouse_id');

        $this->assertSame($this->surabaya->id, $this->shopeeSby->fresh()->warehouse_id);
    }

    public function test_sync_all_only_queues_accessible_shops(): void
    {
        Queue::fake();
        $this->actingAs($this->surabayaUser())->post('/marketplace/sync-all')->assertRedirect();

        Queue::assertPushed(RunMarketplaceTask::class, 2);
        Queue::assertPushed(RunMarketplaceTask::class, fn ($job) => $job->platform === 'shopee' && $job->shopId === $this->shopeeSby->id);
        Queue::assertPushed(RunMarketplaceTask::class, fn ($job) => $job->platform === 'tiktok' && $job->shopId === $this->tiktokSby->id);
    }

    public function test_limited_user_only_sees_orders_of_their_warehouse(): void
    {
        $own = $this->order($this->shopeeSby, 'SBY-ORDER-1');
        $other = $this->order($this->shopeeJkt, 'JKT-ORDER-1');

        $this->actingAs($this->surabayaUser());

        $this->get('/sales/orders')->assertOk()->assertSee($own->number)->assertDontSee($other->number);
        $this->get("/sales/orders/{$own->id}")->assertOk();
        $this->get("/sales/orders/{$other->id}")->assertNotFound();
    }

    public function test_orders_follow_the_shop_warehouse_too(): void
    {
        // Pesanan tercatat di gudang Surabaya, tapi tokonya kini terhubung ke Jakarta.
        $order = $this->order($this->shopeeSby, 'SBY-ORDER-2');
        $manual = SalesOrder::create(['number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $this->surabaya->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Pembeli Manual']);
        $this->shopeeSby->update(['warehouse_id' => $this->jakarta->id]);

        $this->actingAs($this->surabayaUser());

        $this->get('/sales/orders')->assertOk()->assertSee($manual->number)->assertDontSee($order->number)->assertDontSee('Shopee Surabaya Store');
        $this->get("/sales/orders/{$order->id}")->assertNotFound();

        // Super Admin tetap melihat pesanan beserta tokonya.
        $this->actingAs($this->superAdmin());
        $this->assertSame($this->shopeeSby->id, SalesOrder::find($order->id)->shopeeShop?->id);
        $this->get("/sales/orders/{$order->id}")->assertOk();
    }

    public function test_sync_logs_and_push_events_are_limited_to_accessible_shops(): void
    {
        foreach ([[$this->shopeeSby, 'SBY'], [$this->shopeeJkt, 'JKT']] as [$shop, $code]) {
            MarketplaceSyncLog::create([
                'platform' => 'shopee', 'shop_ref' => $shop->id, 'shop_name' => $shop->shop_name, 'part' => 'orders', 'trigger' => 'manual',
                'status' => 'failed', 'error' => "Gagal-{$code}", 'started_at' => now(), 'duration_ms' => 10,
            ]);
            MarketplaceWebhookEvent::create([
                'platform' => 'shopee', 'shop_id' => (string) $shop->shop_id, 'shop_ref' => $shop->id, 'event_type' => 3,
                'order_id' => "PUSH-{$code}", 'payload' => [], 'fingerprint' => sha1($code), 'status' => 'failed',
            ]);
        }

        $this->actingAs($this->surabayaUser());

        $this->get('/marketplace/sync-logs')->assertOk()->assertSee('Gagal-SBY')->assertDontSee('Gagal-JKT')
            ->assertViewHas('summary', fn ($s) => $s['failed_today'] === 1 && $s['failed_push'] === 1 && $s['failed_jobs'] === 0)
            ->assertDontSee('Job Gagal');
        $this->get('/marketplace/sync-logs?tab=push')->assertOk()->assertSee('PUSH-SBY')->assertDontSee('PUSH-JKT');
        $this->post('/marketplace/sync-logs/failed-jobs/retry')->assertForbidden();

        $this->actingAs($this->superAdmin())->get('/marketplace/sync-logs')->assertOk()->assertSee('Gagal-SBY')->assertSee('Gagal-JKT')->assertSee('Job Gagal');
    }

    public function test_marketplace_notifications_only_cover_accessible_shops(): void
    {
        $this->shopeeSby->update(['status' => 'expired', 'last_error' => 'Token SBY dicabut']);
        $this->shopeeJkt->update(['status' => 'expired', 'last_error' => 'Token JKT dicabut']);

        $this->actingAs($this->surabayaUser());
        $problems = app(AlertService::class)->marketplaceIssues()->pluck('problem')->implode(' | ');

        $this->assertStringContainsString('Token SBY dicabut', $problems);
        $this->assertStringNotContainsString('Token JKT dicabut', $problems);
    }
}
