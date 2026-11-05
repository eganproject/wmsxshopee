<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Support\MobileApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;
use ZipArchive;

/**
 * Kelola versi APK aplikasi Android: upload file APK (atau ambil dari GitHub
 * Release), catatan perubahan, dan tanda wajib update.
 */
class ReleaseController extends Controller
{
    public function create(): View
    {
        $props = MobileApp::properties();
        $latest = MobileAppRelease::latestRelease();
        $code = (int) ($props['versionCode'] ?? 1);

        // Usulan: versi di kode (android/version.properties) bila lebih baru dari yang sudah diunggah.
        $release = new MobileAppRelease([
            'version_code' => $latest && $latest->version_code >= $code ? $latest->version_code + 1 : $code,
            'version_name' => $latest && $latest->version_code >= $code ? '' : ($props['versionName'] ?? ''),
        ]);

        return view('mobile-app.releases.create', [
            'release' => $release,
            'latest' => $latest,
            'githubTemplate' => (string) config('mobile.apk_url'),
            'maxUploadMb' => $this->maxUploadMb(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $latestCode = (int) MobileAppRelease::max('version_code');

        $data = $request->validate([
            'source' => ['required', Rule::in(['github', 'upload'])],
            'version_code' => ['required', 'integer', 'min:1', 'max:2100000000', 'gt:'.$latestCode, Rule::unique('mobile_app_releases', 'version_code')],
            'version_name' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-_]*$/'],
            'changelog' => ['nullable', 'string', 'max:3000'],
            'is_mandatory' => ['boolean'],
            'apk' => ['exclude_unless:source,upload', 'required', 'file', 'extensions:apk', 'max:204800'],
        ], [
            'version_code.gt' => "Nomor build harus lebih besar dari versi terakhir ({$latestCode}). HP hanya mau memasang update dengan nomor build yang lebih tinggi.",
            'version_name.regex' => 'Nama versi hanya boleh angka, huruf, titik atau strip, cth. 1.2.0.',
            'apk.extensions' => 'File harus berformat .apk.',
            'apk.max' => 'Ukuran APK maksimal 200 MB.',
        ], [
            'version_code' => 'nomor build',
            'version_name' => 'nama versi',
            'apk' => 'file APK',
        ]);

        $disk = Storage::disk(MobileAppRelease::DISK);
        $path = "mobile-releases/optima-gudang-{$data['version_code']}.apk";
        $disk->makeDirectory('mobile-releases');
        $target = $disk->path($path);

        if ($data['source'] === 'upload') {
            $request->file('apk')->move(dirname($target), basename($target));
        } else {
            $this->fetchFromGithub($data['version_name'], $target);
        }

        if (! $this->looksLikeApk($target)) {
            @unlink($target);

            throw ValidationException::withMessages([$data['source'] === 'upload' ? 'apk' : 'source' => 'File ini bukan APK Android yang valid.']);
        }

        $release = MobileAppRelease::create([
            'version_code' => $data['version_code'],
            'version_name' => $data['version_name'],
            'changelog' => $data['changelog'] ?? null,
            'is_mandatory' => $request->boolean('is_mandatory'),
            'file_path' => $path,
            'file_size' => filesize($target),
            'sha256' => hash_file('sha256', $target),
            'source' => $data['source'],
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('mobile.app')->with('success', "Versi {$release->version_name} diterbitkan. Aplikasi di HP akan menawarkan update saat dibuka.");
    }

    public function edit(MobileAppRelease $release): View
    {
        return view('mobile-app.releases.edit', ['release' => $release]);
    }

    /** Hanya catatan & tanda wajib yang bisa diubah; file & nomor build tetap. */
    public function update(Request $request, MobileAppRelease $release): RedirectResponse
    {
        $data = $request->validate([
            'changelog' => ['nullable', 'string', 'max:3000'],
            'is_mandatory' => ['boolean'],
        ]);

        $release->update([
            'changelog' => $data['changelog'] ?? null,
            'is_mandatory' => $request->boolean('is_mandatory'),
        ]);

        return redirect()->route('mobile.app')->with('success', "Versi {$release->version_name} diperbarui.");
    }

    public function destroy(MobileAppRelease $release): RedirectResponse
    {
        Storage::disk(MobileAppRelease::DISK)->delete($release->file_path);
        $release->delete();

        return redirect()->route('mobile.app')->with('success', "Versi {$release->version_name} dihapus. HP akan ditawari versi sebelumnya bila belum terpasang.");
    }

    /** Unduh optima-gudang.apk dari GitHub Release android-v{versi} (dibuat otomatis workflow Android). */
    private function fetchFromGithub(string $versionName, string $target): void
    {
        $url = MobileApp::githubUrl($versionName);

        try {
            $response = Http::timeout(600)->withOptions(['sink' => $target])->get($url);
        } catch (Throwable $e) {
            @unlink($target);

            throw ValidationException::withMessages(['source' => 'Gagal mengambil APK dari GitHub: '.$e->getMessage()]);
        }

        if (! $response->successful()) {
            @unlink($target);

            throw ValidationException::withMessages(['source' => "APK versi {$versionName} belum ada di GitHub Release (HTTP {$response->status()}). Pastikan perubahan Android sudah di-merge ke main dan build Actions selesai, atau upload file APK."]);
        }
    }

    /** APK = file zip yang berisi AndroidManifest.xml. */
    private function looksLikeApk(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 100) {
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $ok = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();

        return $ok;
    }

    /** Batas upload PHP (upload_max_filesize / post_max_size), untuk petunjuk di form. */
    private function maxUploadMb(): int
    {
        $toMb = function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024,
                'k' => (int) ceil($number / 1024),
                'm' => $number,
                default => (int) ceil($number / 1048576),
            };
        };

        $limits = array_filter([$toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size'))]);

        return $limits ? min($limits) : 0;
    }
}
