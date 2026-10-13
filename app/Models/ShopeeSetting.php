<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kredensial app Shopee (Seller In House System). Hanya satu baris; Partner ID
 * & Key test (sandbox) dan live disimpan terpisah, environment memilih yang aktif.
 */
class ShopeeSetting extends Model
{
    public const ENVIRONMENTS = ['test' => 'Test (Sandbox)', 'live' => 'Live (Produksi)'];

    protected $fillable = [
        'environment',
        'test_partner_id',
        'test_partner_key',
        'live_partner_id',
        'live_partner_key',
        'redirect_base_url',
    ];

    protected $hidden = ['test_partner_key', 'live_partner_key'];

    protected function casts(): array
    {
        return [
            'test_partner_id' => 'integer',
            'live_partner_id' => 'integer',
            'test_partner_key' => 'encrypted',
            'live_partner_key' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static(['environment' => 'test']);
    }

    public function partnerId(?string $environment = null): ?int
    {
        return $this->{($environment ?? $this->environment).'_partner_id'};
    }

    public function partnerKey(?string $environment = null): ?string
    {
        return $this->{($environment ?? $this->environment).'_partner_key'};
    }

    public function isConfigured(?string $environment = null): bool
    {
        return $this->partnerId($environment) && filled($this->partnerKey($environment));
    }

    /** URL callback yang harus didaftarkan sebagai Redirect URL di Shopee Open Platform. */
    public function callbackUrl(): string
    {
        $path = route('shopee.callback', absolute: false);

        return $this->redirect_base_url
            ? rtrim($this->redirect_base_url, '/').$path
            : url($path);
    }
}
