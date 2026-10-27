<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--prune : Hapus backup yang lebih tua dari config backup.keep_days (dan backup.offsite.keep_days di offsite)}';

    protected $description = 'Backup seluruh database ke storage/app/backups, lalu salin ke penyimpanan offsite bila diatur';

    public function handle(DatabaseBackup $backup): int
    {
        $name = $backup->create();
        $this->info("Backup tersimpan: {$name} (".Number::fileSize(filesize($backup->path($name)), 1).')');

        $result = self::SUCCESS;

        if ($backup->offsiteEnabled()) {
            try {
                $backup->upload($name);
                $this->info('Disalin ke offsite ('.config('backup.offsite.disk').').');
            } catch (Throwable $e) {
                // Backup lokal tetap ada; kegagalan offsite dicatat dan muncul di lonceng notifikasi.
                report($e);
                $this->error('Gagal menyalin ke offsite: '.$e->getMessage());
                $result = self::FAILURE;
            }
        }

        if ($this->option('prune')) {
            $deleted = $backup->prune(config('backup.keep_days'));
            $this->line("{$deleted} backup lama dihapus (lebih dari ".config('backup.keep_days').' hari).');

            if ($backup->offsiteEnabled() && $result === self::SUCCESS) {
                try {
                    $deleted = $backup->pruneOffsite(config('backup.offsite.keep_days'));
                    $this->line("{$deleted} backup offsite lama dihapus (lebih dari ".config('backup.offsite.keep_days').' hari).');
                } catch (Throwable $e) {
                    report($e);
                    $this->warn('Gagal merapikan backup offsite: '.$e->getMessage());
                }
            }
        }

        return $result;
    }
}
