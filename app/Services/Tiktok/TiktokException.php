<?php

namespace App\Services\Tiktok;

use RuntimeException;

/** Error dari TikTok Shop (code bukan 0) atau kredensial/otorisasi belum siap. */
class TiktokException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $tiktokCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
        public readonly bool $connectionFailed = false,
        // Koneksi tidak pernah terbuka: request belum diterima server.
        public readonly bool $notSent = false,
    ) {
        parent::__construct($message);
    }

    /** Kode TikTok untuk "Too many requests" (batas panggilan API terlampaui). */
    public const RATE_LIMIT_CODES = [36009002, 36009004];

    /** Kode TikTok yang berarti app belum punya izin (paket API) untuk panggilan ini. */
    public const MISSING_SCOPE_CODES = [105005];

    /** Kode grup 360 yang pasti berarti otorisasi toko tidak berlaku (cipher toko tidak valid). */
    public const REVOKED_CODES = [36004004];

    /**
     * Error yang berarti toko harus diotorisasi ulang: token ditolak (grup 105xxx) atau
     * cipher toko tidak valid. Kode lain berawalan 360 (batas panggilan, parameter salah,
     * gangguan server TikTok) hanya gangguan atau salah isi: token tetap sah, jadi toko
     * tidak ditandai "Perlu otorisasi ulang" (yang menghentikan semua sinkron toko itu).
     * Izin API yang belum disetujui (105005) juga bukan masalah token: cukup error
     * di panggilan itu, sinkron lain jalan terus.
     */
    public function needsReauthorization(): bool
    {
        if ($this->isRateLimited() || in_array($this->tiktokCode, self::MISSING_SCOPE_CODES, true)) {
            return false;
        }

        return str_starts_with((string) $this->tiktokCode, '105') || in_array($this->tiktokCode, self::REVOKED_CODES, true);
    }

    /**
     * Perbarui token ditolak TikTok (refresh token tidak berlaku / otorisasi dicabut).
     * Dipakai hanya untuk balasan host auth, yang errornya hampir selalu soal token.
     */
    public function refreshRejected(): bool
    {
        return $this->needsReauthorization()
            || (! $this->isRateLimited() && str_starts_with((string) $this->tiktokCode, '360'));
    }

    /** TikTok menolak karena terlalu banyak panggilan; cukup tunggu lalu ulangi. */
    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429
            || in_array($this->tiktokCode, self::RATE_LIMIT_CODES, true)
            || str_contains(strtolower($this->getMessage()), 'too many request');
    }

    /** Access token ditolak / kedaluwarsa (105001, 105002): cukup perbarui token lalu ulangi. */
    public function invalidAccessToken(): bool
    {
        return in_array($this->tiktokCode, [105001, 105002], true);
    }
}
