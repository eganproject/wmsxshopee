<?php

namespace Tests\Feature;

use App\Models\MobileAppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Update aplikasi Android dari dalam aplikasi: upload versi, cek versi, unduh APK. */
class MobileAppUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MobileAppRelease::DISK);
        $this->admin = User::where('username', 'superadmin')->first();
        $this->actingAs($this->admin);
    }

    /** File APK palsu: zip berisi AndroidManifest.xml. */
    private function apkBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', str_repeat('x', 200));
        $zip->addFromString('classes.dex', 'dex');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function apk(string $name = 'optima-gudang.apk'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->apkBytes());
    }

    private function publish(array $data = []): MobileAppRelease
    {
        $this->post('/aplikasi-android/versi', [
            'source' => 'upload',
            'version_code' => 2,
            'version_name' => '1.1.0',
            'changelog' => "Update dari dalam aplikasi\nPerbaikan scan",
            'is_mandatory' => 0,
            'apk' => $this->apk(),
            ...$data,
        ])->assertSessionHasNoErrors()->assertRedirect('/aplikasi-android');

        return MobileAppRelease::orderByDesc('version_code')->firstOrFail();
    }

    public function test_upload_publishes_a_release_that_the_app_is_offered(): void
    {
        $release = $this->publish();

        $this->assertSame('upload', $release->source);
        $this->assertSame(64, strlen($release->sha256));
        Storage::disk(MobileAppRelease::DISK)->assertExists($release->file_path);

        $this->withHeader('User-Agent', 'Mozilla/5.0 OptimaGudang/1.0.0 (1)')
            ->getJson('/m/app-version')->assertOk()
            ->assertJsonPath('versionCode', 2)
            ->assertJsonPath('versionName', '1.1.0')
            ->assertJsonPath('mandatory', false)
            ->assertJsonPath('sha256', $release->sha256)
            ->assertJsonPath('changelog', "Update dari dalam aplikasi\nPerbaikan scan");

        $this->get('/aplikasi-android')->assertOk()
            ->assertSee('Perbaikan scan')
            ->assertSee('/aplikasi-android/download/'.$release->id, false)
            ->assertDontSee('releases/download/android-v', false);
    }

    public function test_apk_downloads_only_through_a_signed_link(): void
    {
        $release = $this->publish();
        $url = $this->getJson('/m/app-version')->json('url');

        auth()->logout();
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('optima-gudang-1.1.0.apk', $response->headers->get('Content-Disposition'));

        $this->get("/aplikasi-android/download/{$release->id}/optima-gudang.apk")->assertForbidden();
        $this->get($url.'x')->assertForbidden();
    }

    public function test_a_skipped_mandatory_version_still_forces_the_update(): void
    {
        $this->publish(['version_code' => 2, 'version_name' => '1.1.0', 'is_mandatory' => 1]);
        $this->publish(['version_code' => 3, 'version_name' => '1.2.0', 'is_mandatory' => 0]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 OptimaGudang/1.0.0 (1)')
            ->getJson('/m/app-version')->assertJsonPath('versionCode', 3)->assertJsonPath('mandatory', true);
        $this->withHeader('User-Agent', 'Mozilla/5.0 OptimaGudang/1.1.0 (2)')
            ->getJson('/m/app-version')->assertJsonPath('mandatory', false);
        // Browser biasa tidak pernah diwajibkan.
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome')
            ->getJson('/m/app-version')->assertJsonPath('mandatory', false);
    }

    public function test_build_number_must_increase_and_file_must_be_an_apk(): void
    {
        $this->publish(['version_code' => 5, 'version_name' => '1.5.0']);

        $this->post('/aplikasi-android/versi', ['source' => 'upload', 'version_code' => 5, 'version_name' => '1.5.1', 'apk' => $this->apk()])
            ->assertSessionHasErrors('version_code');
        $this->post('/aplikasi-android/versi', ['source' => 'upload', 'version_code' => 4, 'version_name' => '1.4.0', 'apk' => $this->apk()])
            ->assertSessionHasErrors('version_code');
        $this->post('/aplikasi-android/versi', ['source' => 'upload', 'version_code' => 6, 'version_name' => '1.6.0', 'apk' => UploadedFile::fake()->createWithContent('palsu.apk', 'bukan apk sama sekali, cuma teks biasa yang panjang sekali supaya lewat batas ukuran minimal seratus byte ya ya ya')])
            ->assertSessionHasErrors('apk');
        $this->post('/aplikasi-android/versi', ['source' => 'upload', 'version_code' => 6, 'version_name' => '1.6.0'])
            ->assertSessionHasErrors('apk');

        $this->assertSame(1, MobileAppRelease::count());
    }

    public function test_release_can_be_fetched_from_github(): void
    {
        Http::fake(['github.com/*' => Http::response($this->apkBytes())]);

        $this->post('/aplikasi-android/versi', ['source' => 'github', 'version_code' => 2, 'version_name' => '1.1.0'])
            ->assertSessionHasNoErrors();

        $release = MobileAppRelease::firstOrFail();
        $this->assertSame('github', $release->source);
        Storage::disk(MobileAppRelease::DISK)->assertExists($release->file_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'releases/download/android-v1.1.0/optima-gudang.apk'));
    }

    public function test_missing_github_release_shows_a_clear_error(): void
    {
        Http::fake(['github.com/*' => Http::response('Not Found', 404)]);

        $this->post('/aplikasi-android/versi', ['source' => 'github', 'version_code' => 2, 'version_name' => '9.9.9'])
            ->assertSessionHasErrors(['source' => 'APK versi 9.9.9 belum ada di GitHub Release (HTTP 404). Pastikan perubahan Android sudah di-merge ke main dan build Actions selesai, atau upload file APK.']);
        $this->assertSame(0, MobileAppRelease::count());
    }

    public function test_release_notes_and_mandatory_flag_can_be_edited_and_release_deleted(): void
    {
        $release = $this->publish();
        $this->get("/aplikasi-android/versi/{$release->id}/edit")->assertOk()->assertSee('Wajib update');

        $this->put("/aplikasi-android/versi/{$release->id}", ['changelog' => 'Catatan baru', 'is_mandatory' => 1])->assertRedirect('/aplikasi-android');
        $this->assertTrue($release->fresh()->is_mandatory);
        $this->assertSame('Catatan baru', $release->fresh()->changelog);

        $this->delete("/aplikasi-android/versi/{$release->id}")->assertRedirect('/aplikasi-android');
        $this->assertModelMissing($release);
        Storage::disk(MobileAppRelease::DISK)->assertMissing($release->file_path);

        // Tanpa versi yang diunggah, kembali ke APK dari GitHub Release.
        $this->getJson('/m/app-version')->assertJsonPath('mandatory', false);
        $this->assertStringContainsString('releases/download/android-v', $this->getJson('/m/app-version')->json('url'));
    }

    public function test_managing_versions_needs_its_own_permission(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo('mobile.access');
        $this->actingAs($staff);

        $this->get('/aplikasi-android')->assertOk()->assertDontSee('Upload Versi Baru');
        $this->get('/aplikasi-android/versi/create')->assertForbidden();
        $this->post('/aplikasi-android/versi', ['source' => 'upload', 'version_code' => 2, 'version_name' => '1.1.0', 'apk' => $this->apk()])->assertForbidden();

        $this->actingAs($this->admin)->get('/aplikasi-android/versi/create')->assertOk()
            ->assertSee('Ambil dari GitHub Release')
            ->assertSee('value="1.1.0"', false);
    }

    public function test_app_shell_includes_the_update_prompt(): void
    {
        $this->get('/m')->assertOk()->assertSee('Update tersedia')->assertSee('$store.update.available', false);
        $this->get('/m/account')->assertOk()->assertSee('Cek update aplikasi');
    }
}
