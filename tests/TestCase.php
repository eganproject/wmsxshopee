<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Jeda coba ulang ke Shopee/TikTok tidak benar-benar ditunggu saat tes.
        Sleep::fake();
    }

    /**
     * Ganti user di tengah tes = login baru. Lupakan sidik password sesi user
     * sebelumnya, supaya middleware auth.session tidak menganggapnya sesi basi.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session')) {
            $this->app['session']->forget('password_hash_'.($guard ?? 'web'));
        }

        return parent::be($user, $guard);
    }
}
