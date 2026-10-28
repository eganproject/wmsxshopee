<?php

namespace App\Console\Commands;

use App\Models\SalesOrder;
use App\Services\HeldOrderRetry;
use Illuminate\Console\Command;

class RetryHeldOrders extends Command
{
    protected $signature = 'orders:retry-held';

    protected $description = 'Konfirmasi ulang pesanan marketplace yang tertahan karena stok kurang';

    public function handle(HeldOrderRetry $retry): int
    {
        $held = SalesOrder::withoutGlobalScope('warehouse_access')->stockHeld()->count();

        if ($held === 0) {
            $this->info('Tidak ada pesanan yang tertahan stok.');

            return self::SUCCESS;
        }

        $confirmed = $retry->retryAll();
        $this->line("{$confirmed} dari {$held} pesanan tertahan stok berhasil dikonfirmasi.");

        return self::SUCCESS;
    }
}
