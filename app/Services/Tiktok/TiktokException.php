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
    ) {
        parent::__construct($message);
    }

    /**
     * Error yang berarti toko harus diotorisasi ulang. Kode grup 105xxx
     * (access/refresh token tidak valid / kedaluwarsa) dan 360xxx (otorisasi
     * toko dicabut / cipher tidak valid) menurut daftar error TikTok.
     */
    public function needsReauthorization(): bool
    {
        return in_array(substr((string) $this->tiktokCode, 0, 3), ['105', '360'], true);
    }

    /** Access token ditolak / kedaluwarsa (105001, 105002): cukup perbarui token lalu ulangi. */
    public function invalidAccessToken(): bool
    {
        return in_array($this->tiktokCode, [105001, 105002], true);
    }
}
