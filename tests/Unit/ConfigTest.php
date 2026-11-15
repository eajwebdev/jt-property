<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_business_timezone_defaults_to_manila_without_env(): void
    {
        // The value must not depend on APP_TIMEZONE being present in .env.
        $previous = $_ENV['APP_TIMEZONE'] ?? null;
        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
        putenv('APP_TIMEZONE');

        try {
            $config = require base_path('config/app.php');
            $this->assertSame('Asia/Manila', $config['timezone']);
        } finally {
            if ($previous !== null) {
                $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = $previous;
                putenv("APP_TIMEZONE={$previous}");
            }
        }
    }
}
