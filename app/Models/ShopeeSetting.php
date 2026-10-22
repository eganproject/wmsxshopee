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
        'test_push_key',
        'live_push_key',
        'redirect_base_url',
        'last_push_at',
        'last_push_result',
    ];

    protected $hidden = ['test_partner_key', 'live_partner_key', 'test_push_key', 'live_push_key'];

    protected function casts(): array
    {
        return [
            'test_partner_id' => 'integer',
            'live_partner_id' => 'integer',
            'test_partner_key' => 'encrypted',
            'live_partner_key' => 'encrypted',
            'test_push_key' => 'encrypted',
            'live_push_key' => 'encrypted',
            'last_push_at' => 'datetime',
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

    /** Live Push Partner Key (menu Push Mechanism di console Shopee). */
    public function pushKey(?string $environment = null): ?string
    {
        return $this->{($environment ?? $this->environment).'_push_key'};
    }

    /**
     * Key yang mungkin dipakai Shopee untuk menandatangani push: Live Push
     * Partner Key bila diisi, lalu partner key app (app lama memakai key ini).
     *
     * @return list<string>
     */
    public function pushSigningKeys(string $environment): array
    {
        return array_values(array_unique(array_filter([$this->pushKey($environment), $this->partnerKey($environment)], 'filled')));
    }

    public function isConfigured(?string $environment = null): bool
    {
        return $this->partnerId($environment) && filled($this->partnerKey($environment));
    }

    /** URL callback yang harus didaftarkan sebagai Redirect URL di Shopee Open Platform. */
    public function callbackUrl(): string
    {
        return $this->publicUrl(route('shopee.callback', absolute: false));
    }

    /** URL Push Mechanism (Live Push) yang didaftarkan di console app Shopee. */
    public function webhookUrl(): string
    {
        return $this->publicUrl(route('shopee.webhook', absolute: false));
    }

    private function publicUrl(string $path): string
    {
        return $this->redirect_base_url ? rtrim($this->redirect_base_url, '/').$path : url($path);
    }
}
