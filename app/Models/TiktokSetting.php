<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kredensial app TikTok Shop Partner Center. Hanya satu baris. TikTok tidak
 * punya sandbox terpisah sejak API 202309, jadi tidak ada pilihan mode.
 */
class TiktokSetting extends Model
{
    protected $fillable = ['app_key', 'app_secret', 'service_id', 'redirect_base_url'];

    protected $hidden = ['app_secret'];

    protected function casts(): array
    {
        return ['app_secret' => 'encrypted'];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function isConfigured(): bool
    {
        return filled($this->app_key) && filled($this->app_secret);
    }

    /** URL callback yang harus didaftarkan sebagai Redirect URL app di Partner Center. */
    public function callbackUrl(): string
    {
        return $this->publicUrl(route('tiktok.callback', absolute: false));
    }

    /** URL webhook yang didaftarkan di Partner Center › Manage Webhooks. */
    public function webhookUrl(): string
    {
        return $this->publicUrl(route('tiktok.webhook', absolute: false));
    }

    private function publicUrl(string $path): string
    {
        return $this->redirect_base_url ? rtrim($this->redirect_base_url, '/').$path : url($path);
    }
}
