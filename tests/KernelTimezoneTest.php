<?php

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

class KernelTimezoneTest extends TestCase
{
    private string $previousTimezone = 'UTC';
    private mixed $previousServerEnv = null;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousServerEnv = $_SERVER['APP_TIMEZONE'] ?? null;
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        if ($this->previousServerEnv === null) {
            unset($_SERVER['APP_TIMEZONE']);
        } else {
            $_SERVER['APP_TIMEZONE'] = $this->previousServerEnv;
        }
    }

    public function testBootAppliesAppTimezoneEnvVar(): void
    {
        $_SERVER['APP_TIMEZONE'] = 'Europe/Paris';
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('Europe/Paris', date_default_timezone_get());
        $kernel->shutdown();
    }

    public function testBootFallsBackToUtcOnInvalidTimezone(): void
    {
        $_SERVER['APP_TIMEZONE'] = 'Mars/Olympus_Mons';
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('UTC', date_default_timezone_get());
        $kernel->shutdown();
    }

    public function testBootDefaultsToUtcWhenEnvMissing(): void
    {
        unset($_SERVER['APP_TIMEZONE']);
        // Pretend the previous boot left a non-UTC tz behind.
        date_default_timezone_set('Asia/Tokyo');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('UTC', date_default_timezone_get());
        $kernel->shutdown();
    }
}
