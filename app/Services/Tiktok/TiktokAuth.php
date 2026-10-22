<?php

namespace App\Services\Tiktok;

use App\Models\TiktokShop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Otorisasi toko & siklus token TikTok Shop. Satu otorisasi (satu akun seller)
 * bisa mencakup beberapa toko; semuanya memakai token yang sama, jadi refresh
 * memperbarui semua toko dengan open_id yang sama.
 */
class TiktokAuth
{
    /**
     * Tukar code dari callback menjadi token, ambil daftar toko yang diotorisasi
     * lalu simpan / perbarui tokonya.
     *
     * @return list<TiktokShop>
     */
    public function connect(TiktokClient $client, string $code, ?int $warehouseId): array
    {
        $token = $client->token('authorized_code', $code);
        $attributes = $this->tokenAttributes($token);

        $authorized = $client->request('GET', '/authorization/shops', $attributes['access_token'])['shops'] ?? [];

        if (! $authorized) {
            throw new TiktokException('TikTok tidak mengembalikan toko apa pun untuk otorisasi ini.');
        }

        return DB::transaction(fn () => array_map(function (array $info) use ($attributes, $token, $warehouseId) {
            $shop = TiktokShop::firstOrNew(['shop_id' => (string) $info['id']]);
            $shop->fill([
                ...$attributes,
                'shop_cipher' => $info['cipher'] ?? null,
                'shop_code' => $info['code'] ?? null,
                'shop_name' => $info['name'] ?? $shop->shop_name,
                'region' => $info['region'] ?? null,
                'seller_type' => $info['seller_type'] ?? null,
                'open_id' => $token['open_id'] ?? $shop->open_id,
                'is_active' => true,
                'connected_at' => now(),
                'last_error' => null,
            ]);

            if ($warehouseId) {
                $shop->warehouse_id = $warehouseId;
            }

            $shop->save();

            return $shop;
        }, $authorized));
    }

    /** Access token yang masih berlaku; diperbarui dulu bila hampir kedaluwarsa. */
    public function freshAccessToken(TiktokShop $shop, ?TiktokClient $client = null): string
    {
        if ($shop->accessTokenExpiresSoon(10)) {
            $this->refresh($shop, $client);
        }

        return $shop->access_token;
    }

    /**
     * Perbarui access token toko (dan toko lain dari otorisasi yang sama).
     * $onlyIfExpiringWithin dipakai penjadwal supaya token yang masih lama dilewati.
     */
    public function refresh(TiktokShop $shop, ?TiktokClient $client = null, ?int $onlyIfExpiringWithin = null): bool
    {
        $client ??= TiktokClient::make();

        return Cache::lock('tiktok:refresh:'.($shop->open_id ?: $shop->id), 30)->block(15, function () use ($shop, $client, $onlyIfExpiringWithin) {
            $shop->refresh();

            if (! $shop->isConnected()) {
                throw new TiktokException("Toko {$shop->displayName()} belum terhubung atau perlu diotorisasi ulang.");
            }

            if ($onlyIfExpiringWithin !== null && ! $shop->accessTokenExpiresSoon($onlyIfExpiringWithin)) {
                return false;
            }

            $siblings = TiktokShop::query()
                ->when($shop->open_id, fn ($q) => $q->where('open_id', $shop->open_id), fn ($q) => $q->whereKey($shop->id))
                ->get();

            try {
                $attributes = $this->tokenAttributes($client->token('refresh_token', $shop->refresh_token));
            } catch (TiktokException $e) {
                $siblings->each->update([
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'status' => $e->needsReauthorization() ? 'expired' : $shop->status,
                ]);

                throw $e;
            }

            $siblings->each->update([...$attributes, 'last_error' => null]);
            $shop->refresh();

            return true;
        });
    }

    /** Perbarui nama, region & cipher toko dari /authorization/shops. */
    public function syncShopInfo(TiktokShop $shop, ?TiktokClient $client = null): void
    {
        $client ??= TiktokClient::make();
        $shops = $client->request('GET', '/authorization/shops', $this->freshAccessToken($shop, $client))['shops'] ?? [];
        $info = collect($shops)->firstWhere('id', $shop->shop_id);

        if (! $info) {
            $shop->update(['status' => 'expired', 'last_error' => 'Toko tidak lagi ada di daftar otorisasi TikTok. Hubungkan ulang toko.']);

            throw new TiktokException("Toko {$shop->displayName()} tidak lagi diotorisasi di TikTok.");
        }

        $shop->update([
            'shop_cipher' => $info['cipher'] ?? $shop->shop_cipher,
            'shop_code' => $info['code'] ?? $shop->shop_code,
            'shop_name' => $info['name'] ?? $shop->shop_name,
            'region' => $info['region'] ?? $shop->region,
            'seller_type' => $info['seller_type'] ?? $shop->seller_type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    private function tokenAttributes(array $token): array
    {
        if (blank($token['access_token'] ?? null) || blank($token['refresh_token'] ?? null)) {
            throw new TiktokException('Balasan token dari TikTok tidak lengkap.');
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            // TikTok mengirim masa berlaku sebagai unix timestamp, bukan durasi.
            'access_token_expires_at' => $this->expiry($token['access_token_expire_in'] ?? null, now()->addDays(7)),
            'refresh_token_expires_at' => $this->expiry($token['refresh_token_expire_in'] ?? null, now()->addDays(30)),
            'last_refreshed_at' => now(),
            'status' => 'connected',
        ];
    }

    private function expiry(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return $fallback;
        }

        // Jaga-jaga bila yang dikirim durasi (detik), bukan timestamp.
        $expiry = (int) $value < 1_000_000_000 ? now()->addSeconds((int) $value) : Carbon::createFromTimestamp((int) $value);

        // TikTok bisa mengirim masa berlaku ± 100 tahun (otorisasi "Unlimited"), padahal
        // kolom TIMESTAMP MySQL hanya sampai 2038-01-19. Dibatasi supaya tetap tersimpan.
        return $expiry->min(Carbon::create(2037, 12, 31, 0, 0, 0, 'UTC'));
    }
}
