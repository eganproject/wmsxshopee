<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Toko TikTok Shop yang sudah diotorisasi pemiliknya, terhubung ke satu gudang pengirim. */
class TiktokShop extends Model
{
    use LogsActivity;

    public const STATUSES = ShopeeShop::STATUSES;

    protected $fillable = [
        'shop_id',
        'shop_cipher',
        'shop_code',
        'shop_name',
        'region',
        'seller_type',
        'open_id',
        'warehouse_id',
        'tiktok_warehouse_id',
        'status',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'connected_at',
        'last_refreshed_at',
        'last_synced_at',
        'orders_synced_until',
        'returns_synced_until',
        'stock_pushed_at',
        'last_error',
        'is_active',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'shop_cipher'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'orders_synced_until' => 'datetime',
            'returns_synced_until' => 'datetime',
            'stock_pushed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(TiktokSkuMapping::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** Nama saluran di pesanan, cth. "TikTok · Optima Official". */
    public function channelName(): string
    {
        return 'TikTok · '.$this->displayName();
    }

    public function displayName(): string
    {
        return $this->shop_name ?: 'Toko #'.$this->shop_id;
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && filled($this->refresh_token);
    }

    /** Siap disinkron: terhubung, aktif, punya gudang pengirim dan cipher toko. */
    public function isSyncable(): bool
    {
        return $this->is_active && $this->isConnected() && $this->warehouse_id !== null && filled($this->shop_cipher);
    }

    public function accessTokenExpiresSoon(int $minutes): bool
    {
        return ! $this->access_token || ! $this->access_token_expires_at
            || $this->access_token_expires_at->lte(now()->addMinutes($minutes));
    }

    public function activityModule(): string
    {
        return 'tiktok';
    }

    public function activityName(): string
    {
        return 'Toko TikTok';
    }

    public function activityLabel(): string
    {
        return $this->displayName();
    }

    public function activityIgnored(): array
    {
        return ['access_token', 'refresh_token', 'shop_cipher', 'access_token_expires_at', 'refresh_token_expires_at', 'last_refreshed_at', 'last_synced_at', 'last_error', 'orders_synced_until', 'returns_synced_until', 'stock_pushed_at'];
    }
}
