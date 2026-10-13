<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Database
    |--------------------------------------------------------------------------
    |
    | `php artisan db:backup` menyimpan salinan seluruh database sebagai file
    | .sql.gz di folder di bawah. Scheduler menjalankannya tiap hari pada jam
    | `time`, lalu menghapus backup yang lebih tua dari `keep_days` hari.
    |
    | Scheduler butuh `php artisan schedule:run` tiap menit (Task Scheduler di
    | Windows / cron di Linux), atau biarkan `php artisan schedule:work` jalan.
    |
    */

    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    'time' => env('BACKUP_TIME', '01:00'),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Isi tabel ini tidak ikut dibackup (strukturnya tetap): data sementara.
    'skip_data' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'],

];
