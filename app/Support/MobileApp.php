<?php

namespace App\Support;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Info APK aplikasi Android: versi dibaca dari android/version.properties
 * (sumber yang sama dengan build APK), URL download dari GitHub Release
 * yang diterbitkan workflow .github/workflows/android.yml.
 */
class MobileApp
{
    /** Request datang dari aplikasi Android (WebView menambah "OptimaGudang/x" di user agent). */
    public static function isApp(?Request $request = null): bool
    {
        return str_contains((string) ($request ?? request())->userAgent(), 'OptimaGudang/');
    }

    /** @return array{versionCode: int, versionName: string, url: string} */
    public static function version(): array
    {
        $props = [];
        $file = base_path('android/version.properties');

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $props[$key] = $value;
            }
        }

        $name = $props['versionName'] ?? '1.0.0';

        return [
            'versionCode' => (int) ($props['versionCode'] ?? 1),
            'versionName' => $name,
            'url' => str_replace('{version}', $name, (string) config('mobile.apk_url')),
        ];
    }

    /**
     * Gudang yang bisa dipilih di aplikasi (sudah dibatasi akses gudang user).
     *
     * @return Collection<int, Warehouse>
     */
    public static function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderBy('code')->get();
    }

    /** Gudang aktif aplikasi: pilihan di beranda (session), atau gudang pertama. */
    public static function warehouse(?Request $request = null): ?Warehouse
    {
        $warehouses = self::warehouses();
        $chosen = (int) ($request ?? request())->session()->get('mobile_warehouse_id');

        return $warehouses->firstWhere('id', $chosen) ?? $warehouses->first();
    }
}
