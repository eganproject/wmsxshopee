<?php

namespace App\Services\Shopee;

use RuntimeException;

/** Error dari Shopee (field "error" terisi) atau kredensial/otorisasi belum siap. */
class ShopeeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $shopeeError = null, public readonly ?string $requestId = null)
    {
        parent::__construct($message);
    }

    /** Error yang berarti toko harus diotorisasi ulang oleh pemiliknya. */
    public function needsReauthorization(): bool
    {
        return in_array($this->shopeeError, ['error_auth', 'invalid_acceess_token', 'invalid_access_token', 'invalid_refresh_token', 'refresh_token_expired', 'error_param_shop_id_not_found'], true)
            || str_contains((string) $this->shopeeError, 'auth');
    }
}
