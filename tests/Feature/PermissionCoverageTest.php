<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjaga hak akses: setiap halaman & aksi setelah login harus dijaga permission,
 * dan link ke modul lain hanya tampil kalau user boleh membukanya.
 */
class PermissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /**
     * Route login yang sengaja tanpa permission: halaman pribadi, redirect URL lama,
     * atau dicek per jenis di controller (mutasi stok: inventory.create / inventory.adjust).
     */
    private const OPEN_ROUTES = [
        'logout', 'dashboard', 'notifications.index', 'settings', 'search',
        'profile.edit', 'profile.update', 'profile.password',
        'inventory.movements.create', 'inventory.movements.store',
        'shopee.settings.edit', 'shopee.shops.index', 'shopee.products.index',
        'tiktok.settings.edit', 'tiktok.shops.index',
    ];

    /** @return list<RouteDefinition> */
    private function authRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => in_array('auth', $route->gatherMiddleware(), true))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function abilities(RouteDefinition $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))
            ->map(fn (string $m) => Str::after($m, 'can:'))
            ->values()
            ->all();
    }

    public function test_every_logged_in_route_is_guarded_by_a_permission(): void
    {
        $unguarded = collect($this->authRoutes())
            ->filter(fn (RouteDefinition $route) => $this->abilities($route) === [])
            ->map(fn (RouteDefinition $route) => $route->getName() ?? $route->uri())
            ->reject(fn (string $name) => in_array($name, self::OPEN_ROUTES, true))
            ->values()
            ->all();

        $this->assertSame([], $unguarded, 'Route baru tanpa middleware can:..., tambahkan permission-nya.');
    }

    public function test_route_permissions_exist_in_the_permission_list(): void
    {
        $defined = collect(config('permissions.modules'))
            ->flatMap(fn (array $module, string $key) => collect(array_keys($module['actions']))->map(fn ($action) => "{$key}.{$action}"));

        $unknown = collect($this->authRoutes())
            ->flatMap(fn (RouteDefinition $route) => $this->abilities($route))
            ->unique()
            ->reject(fn (string $ability) => $defined->contains($ability) || Gate::has($ability))
            ->values()
            ->all();

        $this->assertSame([], $unknown, 'Permission di route belum terdaftar di config/permissions.php.');
    }

    public function test_user_without_permissions_is_refused_on_every_guarded_page(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->authRoutes() as $route) {
            $uri = $route->uri();

            if ($this->abilities($route) === [] || ! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
                continue;
            }

            $this->get('/'.$uri)->assertForbidden();
        }

        // Halaman tanpa permission tetap terbuka.
        $this->get('/dashboard')->assertOk();
        $this->get('/notifications')->assertOk();
        $this->get('/settings')->assertOk();
        $this->get('/profile')->assertOk();
    }

    public function test_dashboard_statistics_need_dashboard_view(): void
    {
        $role = Role::create(['name' => 'Tanpa Dashboard'])->givePermissionTo(['sales.view', 'inventory.view', 'receipts.view']);
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertViewHas('orders', null)->assertViewHas('lowStock', null)->assertViewHas('chart', null)
            ->assertSee('Belum ada ringkasan');

        $role->givePermissionTo('dashboard.view');
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertSee('Pesanan per Status');
    }

    public function test_links_to_other_modules_only_show_with_their_permission(): void
    {
        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'min_stock' => 50, 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $warehouse->id, 'movement_date' => today()->toDateString()],
            [['product_variant_id' => $variant->id, 'to_location_id' => null, 'qty' => 5]]);

        $order = SalesOrder::create(['number' => 'SO-LINK', 'status' => 'draft', 'warehouse_id' => $warehouse->id, 'order_date' => today(), 'customer_name' => 'Budi']);
        $return = SalesReturn::create(['number' => 'RT-LINK', 'status' => 'draft', 'warehouse_id' => $warehouse->id, 'sales_order_id' => $order->id, 'sender_name' => 'Budi', 'return_date' => today()]);

        // Retur tanpa hak lihat pesanan: nomor pesanan tampil, tetapi bukan link.
        $returnsOnly = User::factory()->create()->assignRole(Role::create(['name' => 'Retur'])->givePermissionTo('returns.view'));
        $this->actingAs($returnsOnly)->get("/sales/returns/{$return->id}")->assertOk()
            ->assertSee('SO-LINK')->assertDontSee(route('sales.orders.show', $order), false);
        $this->get('/sales/returns')->assertOk()->assertSee('SO-LINK')->assertDontSee(route('sales.orders.show', $order), false);

        // Laporan tanpa hak stok: SKU tampil, tanpa link ke halaman Stok / Kartu Stok.
        $reportsOnly = User::factory()->create()->assignRole(Role::create(['name' => 'Laporan'])->givePermissionTo('reports.view'));
        $this->actingAs($reportsOnly)->get('/reports/min-stock')->assertOk()
            ->assertSee('KAOS-M')->assertDontSee(route('inventory.stocks.index'), false);
        $this->get('/reports/stock-locations')->assertOk()->assertSee('KAOS-M')->assertDontSee(route('inventory.stock-card.index'), false);

        // Dengan hak modulnya, link kembali muncul.
        $this->actingAs(User::where('username', 'superadmin')->first())->get("/sales/returns/{$return->id}")
            ->assertSee(route('sales.orders.show', $order), false);
    }
}
