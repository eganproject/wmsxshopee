<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resi / pesanan yang di-scan lagi di QC kemas atau serah terima padahal sudah
 * lolos QC / sudah dikirim. Jejak untuk mencari paket dobel atau label tercetak ulang.
 */
class ScanDuplicateAttempt extends Model
{
    use ScopedToWarehouse;

    public const CONTEXTS = [
        'qc' => 'QC Kemas',
        'handover' => 'Serah Terima',
    ];

    protected $fillable = [
        'code',
        'context',
        'sales_order_id',
        'order_status',
        'warehouse_id',
        'user_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contextLabel(): string
    {
        return self::CONTEXTS[$this->context] ?? $this->context;
    }

    /** Catat satu scan ganda; gagal mencatat tidak boleh menggagalkan scan. */
    public static function record(SalesOrder $order, string $code, string $context, ?int $userId): void
    {
        try {
            self::create([
                'code' => mb_substr($code, 0, 100),
                'context' => $context,
                'sales_order_id' => $order->id,
                'order_status' => $order->status,
                'warehouse_id' => $order->warehouse_id,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
