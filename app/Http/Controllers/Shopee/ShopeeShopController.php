<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Controller;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\Warehouse;
use App\Services\Shopee\ShopeeAuth;
use App\Services\Shopee\ShopeeClient;
use App\Services\Shopee\ShopeeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShopeeShopController extends Controller
{
    public function index(): View
    {
        $setting = ShopeeSetting::current();

        return view('shopee.shops.index', [
            'setting' => $setting,
            'shops' => ShopeeShop::with('warehouse:id,code,name')->withCount([
                'mappings',
                'mappings as unmapped_count' => fn ($q) => $q->whereNull('product_variant_id')->whereNull('bundle_id'),
            ])->orderBy('environment')->orderBy('shop_name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /** Arahkan ke halaman otorisasi Shopee; pemilik toko login Shopee Seller lalu menyetujui. */
    public function connect(Request $request, ShopeeAuth $auth): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ], [], ['warehouse_id' => 'gudang pengirim']);

        $client = ShopeeClient::make();

        if (! $client->isConfigured()) {
            return redirect()->route('shopee.settings.edit')->with('error', 'Isi Partner ID & Partner Key Shopee dulu sebelum menghubungkan toko.');
        }

        $state = Str::random(32);
        $request->session()->put('shopee_auth', [
            'state' => $state,
            'environment' => $client->environment,
            'warehouse_id' => (int) $data['warehouse_id'],
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
        ]);

        $redirect = ShopeeSetting::current()->callbackUrl().'?'.http_build_query(['state' => $state]);

        return redirect()->away($client->authorizationUrl($redirect));
    }

    /** Shopee mengarahkan kembali ke sini dengan ?code=...&shop_id=... (atau main_account_id). */
    public function callback(Request $request, ShopeeAuth $auth): RedirectResponse
    {
        $pending = $request->session()->pull('shopee_auth');

        if (! $pending || $pending['expires_at'] < now()->getTimestamp()
            || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            return redirect()->route('shopee.shops.index')->with('error', 'Sesi otorisasi Shopee tidak valid atau sudah kedaluwarsa. Ulangi dari tombol Hubungkan Toko.');
        }

        $code = (string) $request->query('code');
        $shopId = $request->integer('shop_id') ?: null;
        $mainAccountId = $request->integer('main_account_id') ?: null;

        if ($code === '' || (! $shopId && ! $mainAccountId)) {
            return redirect()->route('shopee.shops.index')->with('error', 'Otorisasi dibatalkan atau Shopee tidak mengirim kode otorisasi.');
        }

        try {
            $shops = $auth->connect(ShopeeClient::make($pending['environment']), $code, $shopId, $mainAccountId, $pending['warehouse_id']);
        } catch (ShopeeException $e) {
            return redirect()->route('shopee.shops.index')->with('error', 'Gagal menghubungkan toko. '.$e->getMessage());
        }

        $names = collect($shops)->map->displayName()->implode(', ');

        return redirect()->route('shopee.shops.index')->with('success', "Toko {$names} berhasil terhubung.");
    }

    public function update(Request $request, ShopeeShop $shop): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['warehouse_id' => 'gudang pengirim']);

        $shop->update([...$data, 'is_active' => $data['is_active'] ?? false]);

        return back()->with('success', "Toko {$shop->displayName()} diperbarui.");
    }

    public function refresh(ShopeeShop $shop, ShopeeAuth $auth): RedirectResponse
    {
        try {
            $client = ShopeeClient::make($shop->environment);
            $auth->refresh($shop, $client);
            $auth->syncShopInfo($client, $shop);
        } catch (ShopeeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token toko {$shop->displayName()} diperbarui.");
    }

    /**
     * Hapus koneksi di Optima (token & pemetaan SKU ikut terhapus). Akses app
     * di sisi Shopee dicabut pemilik toko dari Seller Centre bila perlu.
     */
    public function destroy(ShopeeShop $shop): RedirectResponse
    {
        $shop->delete();

        return redirect()->route('shopee.shops.index')->with('success', "Koneksi toko {$shop->displayName()} dihapus.");
    }
}
