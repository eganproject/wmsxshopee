<?php

namespace App\Support;

use App\Models\MobileAppRelease;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Info APK aplikasi Android. Versi terbaru = APK terakhir yang diunggah di
 * halaman Aplikasi Android (tabel mobile_app_releases), diunduh lewat link
 * bertanda tangan. Bila belum ada, dipakai android/version.properties dan
 * GitHub Release yang diterbitkan workflow .github/workflows/android.yml.
 */
class MobileApp
{
    /** Request datang dari aplikasi Android (WebView menambah "OptimaGudang/x" di user agent). */
    public static function isApp(?Request $request = null): bool
    {
        return str_contains((string) ($request ?? request())->userAgent(), 'OptimaGudang/');
    }

    /** versionCode aplikasi yang membuka halaman ini (dari user agent "OptimaGudang/1.0.0 (1)"), null bila browser biasa. */
    public static function installedVersionCode(?Request $request = null): ?int
    {
        return preg_match('/OptimaGudang\/\S+ \((\d+)\)/', (string) ($request ?? request())->userAgent(), $m) ? (int) $m[1] : null;
    }

    /**
     * Versi APK terbaru. mandatory = ada versi wajib yang lebih baru dari versi
     * terpasang di HP ini (jadi versi wajib yang terlewat tetap memaksa update).
     *
     * @return array{versionCode: int, versionName: string, url: string, changelog: ?string, mandatory: bool, size: ?int, sha256: ?string, publishedAt: ?string}
     */
    public static function version(?Request $request = null, int $linkDays = 1): array
    {
        $release = MobileAppRelease::latestRelease();

        if ($release) {
            $installed = self::installedVersionCode($request);

            return [
                'versionCode' => $release->version_code,
                'versionName' => $release->version_name,
                'url' => self::downloadUrl($release, $linkDays),
                'changelog' => $release->changelog,
                'mandatory' => $installed !== null && MobileAppRelease::query()
                    ->where('version_code', '>', $installed)->where('is_mandatory', true)->exists(),
                'size' => $release->file_size,
                'sha256' => $release->sha256,
                'publishedAt' => $release->created_at?->toIso8601String(),
            ];
        }

        $props = self::properties();
        $name = $props['versionName'] ?? '1.0.0';

        return [
            'versionCode' => (int) ($props['versionCode'] ?? 1),
            'versionName' => $name,
            'url' => self::githubUrl($name),
            'changelog' => null,
            'mandatory' => false,
            'size' => null,
            'sha256' => null,
            'publishedAt' => null,
        ];
    }

    /** Link unduh APK yang bisa dibuka tanpa login (aplikasi lama membukanya lewat browser HP). */
    public static function downloadUrl(MobileAppRelease $release, int $days = 1): string
    {
        return URL::temporarySignedRoute('mobile.download', now()->addDays($days), ['release' => $release->id]);
    }

    /** Isi android/version.properties (versi yang sedang ada di kode). */
    public static function properties(): array
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

        return $props;
    }

    /** Link GitHub Release untuk versi tertentu (bawaan workflow Android). */
    public static function githubUrl(string $versionName): string
    {
        return str_replace('{version}', $versionName, (string) config('mobile.apk_url'));
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
