<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** Push Shopee / webhook TikTok yang sudah lolos verifikasi, menunggu atau selesai diproses. */
class MarketplaceWebhookEvent extends Model
{
    use Prunable;

    /** Berapa hari jejak push yang sudah selesai disimpan. */
    public const KEEP_DAYS = 30;

    protected $fillable = [
        'platform',
        'shop_id',
        'shop_ref',
        'event_type',
        'order_id',
        'payload',
        'fingerprint',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'event_type' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Simpan push; null bila push yang sama persis sudah pernah diterima.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $platform, Model $shop, int $type, ?string $orderId, array $payload, string $rawBody): ?self
    {
        $event = static::createOrFirst(
            ['platform' => $platform, 'fingerprint' => sha1($rawBody)],
            ['shop_id' => (string) $shop->shop_id, 'shop_ref' => $shop->getKey(), 'event_type' => $type, 'order_id' => $orderId ?: null, 'payload' => $payload, 'status' => 'pending'],
        );

        return $event->wasRecentlyCreated ? $event : null;
    }

    public function prunable(): Builder
    {
        return static::whereIn('status', ['processed', 'ignored'])->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
