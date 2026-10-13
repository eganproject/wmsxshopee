<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Toko Shopee yang sudah diotorisasi pemiliknya, terhubung ke satu gudang pengirim. */
class ShopeeShop extends Model
{
    use LogsActivity;

    public const STATUSES = [
        'connected' => 'Terhubung',
        'expired' => 'Perlu otorisasi ulang',
        'disconnected' => 'Diputus',
    ];

    protected $fillable = [
        'environment',
        'shop_id',
        'shop_name',
        'region',
        'warehouse_id',
        'status',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'authorization_expires_at',
        'connected_at',
        'last_refreshed_at',
        'last_synced_at',
        'last_error',
        'is_active',
        'orders_synced_until',
        'returns_synced_until',
        'stock_pushed_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'shop_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'authorization_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'orders_synced_until' => 'datetime',
            'returns_synced_until' => 'datetime',
            'stock_pushed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ShopeeSkuMapping::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** Nama saluran di pesanan & retur, cth. "Shopee · Optima Official". */
    public function channelName(): string
    {
        return 'Shopee · '.$this->displayName();
    }

    /** Siap disinkron: terhubung, aktif dan sudah punya gudang pengirim. */
    public function isSyncable(): bool
    {
        return $this->is_active && $this->isConnected() && $this->warehouse_id !== null;
    }

    public function displayName(): string
    {
        return $this->shop_name ?: 'Toko #'.$this->shop_id;
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && filled($this->refresh_token);
    }

    public function accessTokenExpiresSoon(int $minutes): bool
    {
        return ! $this->access_token || ! $this->access_token_expires_at
            || $this->access_token_expires_at->lte(now()->addMinutes($minutes));
    }

    public function activityModule(): string
    {
        return 'shopee';
    }

    public function activityName(): string
    {
        return 'Toko Shopee';
    }

    public function activityLabel(): string
    {
        return $this->displayName();
    }

    /** Token & jejak waktu teknis tidak perlu masuk log aktivitas. */
    public function activityIgnored(): array
    {
        return ['access_token', 'refresh_token', 'access_token_expires_at', 'refresh_token_expires_at', 'last_refreshed_at', 'last_synced_at', 'last_error', 'orders_synced_until', 'returns_synced_until', 'stock_pushed_at'];
    }
}
