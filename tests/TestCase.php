<?php

namespace Tests;

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
}
