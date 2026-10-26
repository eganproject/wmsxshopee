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

    /**
     * Error yang berarti toko harus diotorisasi ulang. Kode grup 105xxx
     * (access/refresh token tidak valid / kedaluwarsa) dan 360xxx (otorisasi
     * toko dicabut / cipher tidak valid) menurut daftar error TikTok.
     * Batas panggilan (36009002 dst.) juga berawalan 360, tapi itu gangguan
     * sementara: token & otorisasinya tetap sah, jadi tidak dihitung.
     */
    public function needsReauthorization(): bool
    {
        if ($this->isRateLimited()) {
            return false;
        }

        return in_array(substr((string) $this->tiktokCode, 0, 3), ['105', '360'], true);
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
