<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--prune : Hapus backup yang lebih tua dari config backup.keep_days}';

    protected $description = 'Backup seluruh database ke storage/app/backups';

    public function handle(DatabaseBackup $backup): int
    {
        $name = $backup->create();
        $this->info("Backup tersimpan: {$name} (".Number::fileSize(filesize($backup->path($name)), 1).')');

        if ($this->option('prune')) {
            $deleted = $backup->prune(config('backup.keep_days'));
            $this->line("{$deleted} backup lama dihapus (lebih dari ".config('backup.keep_days').' hari).');
        }

        return self::SUCCESS;
    }
}
