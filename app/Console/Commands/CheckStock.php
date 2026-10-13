<?php

namespace App\Console\Commands;

use App\Services\StockIntegrity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStock extends Command
{
    protected $signature = 'stock:check';

    protected $description = 'Periksa saldo stok terhadap kartu stok dan cari saldo minus';

    public function handle(StockIntegrity $integrity): int
    {
        $problems = $integrity->problems();

        if ($problems->isEmpty()) {
            $this->info('Saldo stok cocok dengan kartu stok, tidak ada saldo minus.');

            return self::SUCCESS;
        }

        $this->error("Ditemukan {$problems->count()} saldo stok bermasalah:");
        $this->table(
            ['SKU', 'Gudang', 'Lokasi', 'Saldo', 'Kartu stok', 'Masalah'],
            $problems->map(fn ($row) => [$row['sku'], $row['warehouse'], $row['location'] ?? 'Tanpa bin', $row['stock'], $row['ledger'], $row['problem']])->all(),
        );

        Log::warning('stock:check menemukan saldo stok bermasalah', ['problems' => $problems->all()]);

        return self::FAILURE;
    }
}
