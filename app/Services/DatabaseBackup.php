<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Backup database ke file .sql(.gz) tanpa butuh mysqldump di PATH, jadi jalan
 * juga di Laragon/Windows. Hasilnya bisa dipulihkan lewat HeidiSQL ("Run SQL
 * file") atau `mysql nama_db < file.sql`.
 */
class DatabaseBackup
{
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]+\.sql(\.gz)?$/';

    private const ROWS_PER_INSERT = 200;

    /** Buat backup baru dan kembalikan nama filenya. */
    public function create(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Backup otomatis belum mendukung database {$driver}.");
        }

        File::ensureDirectoryExists($this->directory());

        $gzip = function_exists('gzopen');
        $database = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo((string) $connection->getDatabaseName(), PATHINFO_FILENAME)) ?: 'db';
        $name = 'optima-'.$database.'-'.now()->format('Ymd-His').'.sql'.($gzip ? '.gz' : '');
        $final = $this->directory().DIRECTORY_SEPARATOR.$name;
        $partial = $final.'.partial';

        $handle = $gzip ? gzopen($partial, 'wb6') : fopen($partial, 'wb');
        $write = fn (string $sql) => $gzip ? gzwrite($handle, $sql) : fwrite($handle, $sql);

        try {
            // Satu transaksi supaya semua tabel dibaca dari keadaan yang sama walau
            // ada yang sedang mencatat mutasi saat backup berjalan.
            $connection->beginTransaction();
            $this->dump($connection, $write);
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            $gzip ? gzclose($handle) : fclose($handle);
            File::delete($partial);

            throw $e;
        }

        $gzip ? gzclose($handle) : fclose($handle);
        // File baru muncul dengan nama akhirnya hanya bila backup selesai utuh.
        File::move($partial, $final);

        return $name;
    }

    /**
     * @return Collection<int, array{name: string, size: int, created_at: Carbon}>
     */
    public function all(): Collection
    {
        if (! File::isDirectory($this->directory())) {
            return collect();
        }

        return collect(File::files($this->directory()))
            ->filter(fn ($file) => preg_match(self::NAME_PATTERN, $file->getFilename()))
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'created_at' => Carbon::createFromTimestamp($file->getMTime(), config('app.timezone')),
            ])
            ->sortByDesc('created_at')
            ->values();
    }

    /** Path lengkap file backup; nama dari luar divalidasi supaya tidak bisa keluar folder. */
    public function path(string $name): string
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        if (! preg_match(self::NAME_PATTERN, $name) || ! File::isFile($path)) {
            throw new InvalidArgumentException("Backup {$name} tidak ditemukan.");
        }

        return $path;
    }

    public function delete(string $name): void
    {
        File::delete($this->path($name));
    }

    /** Hapus backup yang lebih tua dari sekian hari; backup terbaru selalu disimpan. */
    public function prune(int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays);

        return $this->all()
            ->skip(1)
            ->filter(fn ($backup) => $backup['created_at']->lt($cutoff))
            ->each(fn ($backup) => $this->delete($backup['name']))
            ->count();
    }

    public function directory(): string
    {
        return rtrim(config('backup.path'), '/\\');
    }

    private function dump(Connection $connection, callable $write): void
    {
        $mysql = $connection->getDriverName() !== 'sqlite';
        $quote = fn (string $identifier) => $mysql ? '`'.str_replace('`', '``', $identifier).'`' : '"'.str_replace('"', '""', $identifier).'"';
        $skipData = config('backup.skip_data', []);

        $write("-- Backup database {$connection->getDatabaseName()} ({$connection->getDriverName()})\n");
        $write('-- Dibuat '.now()->format('Y-m-d H:i:s T').' oleh '.config('app.name')."\n\n");
        $write($mysql
            ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
            : "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");

        foreach ($this->tables($connection) as $table => $create) {
            $write("-- Tabel {$table}\n");
            $write('DROP TABLE IF EXISTS '.$quote($table).";\n");
            $write(rtrim($create, ";\n").";\n");

            if (! in_array($table, $skipData, true)) {
                $this->dumpRows($connection, $table, $quote, $write);
            }

            if (! $mysql) {
                foreach ($connection->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]) as $index) {
                    $write($index->sql.";\n");
                }
            }

            $write("\n");
        }

        $write($mysql ? "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n" : "COMMIT;\nPRAGMA foreign_keys = ON;\n");
    }

    /**
     * @return array<string, string> nama tabel => perintah CREATE TABLE
     */
    private function tables(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->mapWithKeys(fn ($row) => [$row->name => $row->sql])
                ->all();
        }

        return collect($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->sort()
            ->mapWithKeys(fn ($table) => [$table => array_values((array) $connection->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`'))[1]])
            ->all();
    }

    private function dumpRows(Connection $connection, string $table, callable $quote, callable $write): void
    {
        $columns = Schema::connection($connection->getName())->getColumnListing($table);
        $prefix = 'INSERT INTO '.$quote($table).' ('.implode(', ', array_map($quote, $columns)).') VALUES';
        $query = $connection->table($table)->select($columns);
        $rows = in_array('id', $columns, true) ? $query->lazyById(1000) : $query->cursor();
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = '('.implode(', ', array_map(fn ($value) => $this->literal($connection, $value), array_values((array) $row))).')';

            if (count($batch) === self::ROWS_PER_INSERT) {
                $write($prefix."\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch) {
            $write($prefix."\n".implode(",\n", $batch).";\n");
        }
    }

    private function literal(Connection $connection, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $connection->getPdo()->quote((string) $value),
        };
    }
}
