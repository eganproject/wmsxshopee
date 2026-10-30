<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AlertService;
use App\Services\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backups-'.getmypid());
        File::deleteDirectory($this->dir);
        config(['backup.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function sql(string $name): string
    {
        $path = $this->dir.'/'.$name;

        return str_ends_with($name, '.gz') ? gzdecode(file_get_contents($path)) : file_get_contents($path);
    }

    public function test_command_writes_a_restorable_dump(): void
    {
        $this->artisan('db:backup')->expectsOutputToContain('Backup tersimpan')->assertSuccessful();

        $backups = app(DatabaseBackup::class)->all();
        $this->assertCount(1, $backups);
        $this->assertFileDoesNotExist($this->dir.'/'.$backups[0]['name'].'.partial');

        $sql = $this->sql($backups[0]['name']);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $this->assertStringContainsString($sqlite ? 'CREATE TABLE "stocks"' : 'CREATE TABLE `stocks`', $sql);
        $this->assertStringContainsString("'superadmin'", $sql);

        if (! $sqlite) {
            return;
        }

        // Pulihkan ke database kosong: isi harus sama dengan aslinya.
        config(['database.connections.restore' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::connection('restore')->getPdo()->exec($sql);

        foreach (['users', 'permissions', 'roles', 'role_has_permissions', 'couriers', 'units'] as $table) {
            $this->assertSame(DB::table($table)->count(), DB::connection('restore')->table($table)->count(), $table);
        }
        $this->assertSame(
            DB::table('users')->where('username', 'superadmin')->value('password'),
            DB::connection('restore')->table('users')->where('username', 'superadmin')->value('password'),
        );
    }

    public function test_prune_removes_old_backups_but_keeps_the_newest(): void
    {
        File::ensureDirectoryExists($this->dir);
        foreach (['optima-db-old1.sql.gz' => 30, 'optima-db-old2.sql.gz' => 20, 'optima-db-recent.sql.gz' => 2] as $name => $days) {
            File::put($this->dir.'/'.$name, 'x');
            touch($this->dir.'/'.$name, now()->subDays($days)->getTimestamp());
        }
        File::put($this->dir.'/catatan.txt', 'bukan backup');

        $this->assertSame(2, app(DatabaseBackup::class)->prune(14));
        $this->assertSame(['optima-db-recent.sql.gz'], app(DatabaseBackup::class)->all()->pluck('name')->all());
        $this->assertFileExists($this->dir.'/catatan.txt');

        // Bila semua sudah tua, backup terbaru tetap disimpan.
        touch($this->dir.'/optima-db-recent.sql.gz', now()->subDays(40)->getTimestamp());
        $this->assertSame(0, app(DatabaseBackup::class)->prune(14));
    }

    public function test_admin_can_create_download_and_delete_backups(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $this->get('/admin/backups')->assertOk()->assertSee('Belum ada backup');
        $this->post('/admin/backups')->assertRedirect()->assertSessionHas('success');

        $name = app(DatabaseBackup::class)->all()->sole()['name'];
        $this->get('/admin/backups')->assertOk()->assertSee($name);
        $this->get('/admin/backups/'.$name)->assertOk()->assertDownload($name);
        $this->get('/admin/activity')->assertSee("Backup database {$name} dibuat");

        // Nama file dari URL tidak bisa dipakai keluar folder backup.
        $this->get('/admin/backups/..%2F..%2F.env')->assertNotFound();
        $this->get('/admin/backups/tidak-ada.sql.gz')->assertNotFound();

        $this->delete('/admin/backups/'.$name)->assertRedirect()->assertSessionHas('success');
        $this->assertCount(0, app(DatabaseBackup::class)->all());
    }

    public function test_backup_is_copied_offsite_and_old_offsite_copies_are_pruned(): void
    {
        Storage::fake('offsite');
        config(['backup.offsite.disk' => 'offsite', 'backup.offsite.folder' => 'optima-live', 'backup.offsite.keep_days' => 30]);
        Storage::disk('offsite')->put('optima-live/optima-db-20200101-010000.sql.gz', 'lama');

        $this->artisan('db:backup --prune')->expectsOutputToContain('Disalin ke offsite')->assertSuccessful();

        $name = app(DatabaseBackup::class)->all()->first()['name'];
        Storage::disk('offsite')->assertExists('optima-live/'.$name);
        Storage::disk('offsite')->assertMissing('optima-live/optima-db-20200101-010000.sql.gz');

        $status = app(DatabaseBackup::class)->offsiteStatus();
        $this->assertTrue($status['ok']);
        $this->assertSame($name, $status['name']);

        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->get(route('admin.backups.index'))->assertOk()->assertSee('Salinan offsite aktif');
    }

    public function test_failed_offsite_copy_keeps_local_backup_and_raises_an_alert(): void
    {
        config(['backup.offsite.disk' => 'tidak-ada']);

        $this->artisan('db:backup')->expectsOutputToContain('Gagal menyalin ke offsite')->assertFailed();

        $this->assertCount(1, app(DatabaseBackup::class)->all());
        $this->assertFalse(app(DatabaseBackup::class)->offsiteStatus()['ok']);

        $admin = User::where('username', 'superadmin')->first();
        $this->actingAs($admin);
        $this->get(route('admin.backups.index'))->assertOk()->assertSee('Salinan offsite gagal');

        $groups = app(AlertService::class)->groups($admin);
        $this->assertSame(1, $groups['backup']['count']);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Gagal disalin ke offsite');
    }

    public function test_page_warns_when_backups_stay_only_on_this_server(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->get(route('admin.backups.index'))->assertOk()->assertSee('Backup hanya tersimpan di server ini');
    }

    public function test_users_without_permission_cannot_open_backups(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/backups')->assertForbidden();
        $this->post('/admin/backups')->assertForbidden();
        $this->assertCount(0, app(DatabaseBackup::class)->all());
    }

    public function test_backup_and_stock_check_are_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('db:backup --prune')
            ->expectsOutputToContain('stock:check')
            ->assertSuccessful();
    }
}
