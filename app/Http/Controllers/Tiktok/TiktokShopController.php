<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokShop;
use App\Models\Warehouse;
use App\Services\Tiktok\TiktokAuth;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TiktokShopController extends Controller
{
    /** Arahkan ke halaman otorisasi TikTok; pemilik toko login Seller Center lalu menyetujui. */
    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ], [], ['warehouse_id' => 'gudang pengirim']);

        $client = TiktokClient::make();

        if (! $client->isConfigured()) {
            return redirect()->route('marketplace.settings', ['tab' => 'tiktok'])->with('error', 'Isi App Key & App Secret TikTok dulu sebelum menghubungkan toko.');
        }

        $state = Str::random(32);
        $request->session()->put('tiktok_auth', [
            'state' => $state,
            'warehouse_id' => (int) $data['warehouse_id'],
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
        ]);

        return redirect()->away($client->authorizationUrl($state));
    }

    /**
     * TikTok mengarahkan kembali ke sini dengan ?code=...&state=...
     *
     * Otorisasi yang dimulai dari tombol Hubungkan Toko membawa state yang cocok dengan
     * sesi, jadi toko langsung tersimpan. Otorisasi yang dimulai dari sisi TikTok (tautan
     * otorisasi Partner Center, App Store Seller Center, atau browser/perangkat lain)
     * tidak punya state itu: kodenya disimpan sebentar lalu pengguna diminta memilih
     * gudang dan mengonfirmasi, supaya toko tidak hilang begitu saja.
     */
    public function callback(Request $request, TiktokAuth $auth): RedirectResponse|View
    {
        $pending = $request->session()->pull('tiktok_auth');
        $code = (string) ($request->query('code') ?? $request->query('auth_code'));

        if ($code === '') {
            Log::warning('TikTok callback tanpa kode otorisasi.', ['query' => $request->except(['code', 'auth_code'])]);

            return redirect()->route('marketplace.shops', ['platform' => 'tiktok'])->with('error', 'Otorisasi dibatalkan atau TikTok tidak mengirim kode otorisasi.');
        }

        $valid = $pending && $pending['expires_at'] >= now()->getTimestamp()
            && hash_equals((string) $pending['state'], (string) $request->query('state'));

        if (! $valid) {
            Log::info('TikTok callback tanpa sesi Hubungkan Toko; minta konfirmasi.', ['has_session' => (bool) $pending, 'state' => (bool) $request->query('state')]);
            $request->session()->put('tiktok_auth_code', ['code' => $code, 'expires_at' => now()->addMinutes(10)->getTimestamp()]);

            return view('tiktok.confirm', [
                'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                'warehouseId' => $pending['warehouse_id'] ?? null,
            ]);
        }

        return $this->finishConnect($auth, $code, $pending['warehouse_id']);
    }

    /** Lanjutan callback tanpa sesi: pengguna memilih gudang lalu kode ditukar. */
    public function confirm(Request $request, TiktokAuth $auth): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ], [], ['warehouse_id' => 'gudang pengirim']);

        $pending = $request->session()->pull('tiktok_auth_code');

        if (! $pending || $pending['expires_at'] < now()->getTimestamp()) {
            return redirect()->route('marketplace.shops', ['platform' => 'tiktok'])->with('error', 'Kode otorisasi TikTok sudah kedaluwarsa. Ulangi dari tombol Hubungkan Toko.');
        }

        return $this->finishConnect($auth, $pending['code'], (int) $data['warehouse_id']);
    }

    private function finishConnect(TiktokAuth $auth, string $code, ?int $warehouseId): RedirectResponse
    {
        try {
            $shops = $auth->connect(TiktokClient::make(), $code, $warehouseId);
        } catch (TiktokException $e) {
            Log::warning('Gagal menghubungkan toko TikTok: '.$e->getMessage());

            return redirect()->route('marketplace.shops', ['platform' => 'tiktok'])->with('error', 'Gagal menghubungkan toko. '.$e->getMessage());
        }

        $names = collect($shops)->map->displayName()->implode(', ');
        Log::info("Toko TikTok terhubung: {$names}");

        return redirect()->route('marketplace.shops', ['platform' => 'tiktok'])->with('success', "Toko {$names} berhasil terhubung.");
    }

    public function update(Request $request, TiktokShop $shop): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'stock_push' => ['nullable', 'boolean'],
        ], [], ['warehouse_id' => 'gudang pengirim']);

        $shop->update([...$data, 'is_active' => $data['is_active'] ?? false, 'stock_push' => $data['stock_push'] ?? false]);

        return back()->with('success', "Toko {$shop->displayName()} diperbarui.");
    }

    public function refresh(TiktokShop $shop, TiktokAuth $auth): RedirectResponse
    {
        try {
            $client = TiktokClient::make();
            $auth->refresh($shop, $client);
            $auth->syncShopInfo($shop, $client);
        } catch (TiktokException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token toko {$shop->displayName()} diperbarui.");
    }

    /** Tarik pesanan lalu kirim stok sekarang (biasanya berjalan terjadwal). */
    public function sync(Request $request, TiktokShop $shop, TiktokSync $sync): RedirectResponse
    {
        abort_unless($request->user()->canAccessWarehouse($shop->warehouse_id), 403, 'Anda tidak punya akses ke gudang toko ini.');

        try {
            $result = $sync->run($shop);
        } catch (TiktokException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$shop->displayName()}: ".TiktokSync::summary($result));
    }

    /** Hapus koneksi di Optima. Akses app di sisi TikTok dicabut pemilik toko dari Seller Center. */
    public function destroy(TiktokShop $shop): RedirectResponse
    {
        $shop->delete();

        return redirect()->route('marketplace.shops', ['platform' => 'tiktok'])->with('success', "Koneksi toko {$shop->displayName()} dihapus.");
    }
}
