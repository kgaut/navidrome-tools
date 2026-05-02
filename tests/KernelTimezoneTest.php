<?php

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

class KernelTimezoneTest extends TestCase
{
    private string $previousTimezone = 'UTC';
    private mixed $previousServerEnv = null;
    private mixed $previousEnvEnv = null;
    private string|false $previousGetenv = false;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousServerEnv = $_SERVER['APP_TIMEZONE'] ?? null;
        $this->previousEnvEnv = $_ENV['APP_TIMEZONE'] ?? null;
        $this->previousGetenv = getenv('APP_TIMEZONE');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        $this->restoreEnv('APP_TIMEZONE', $this->previousServerEnv, $this->previousEnvEnv, $this->previousGetenv);
    }

    public function testBootAppliesAppTimezoneEnvVar(): void
    {
        $this->setAppTimezone('Europe/Paris');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('Europe/Paris', date_default_timezone_get());
        $kernel->shutdown();
    }

    public function testBootFallsBackToUtcOnInvalidTimezone(): void
    {
        $this->setAppTimezone('Mars/Olympus_Mons');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('UTC', date_default_timezone_get());
        $kernel->shutdown();
    }

    public function testBootDefaultsToUtcWhenEnvMissing(): void
    {
        // Unset all three sources — Kernel::boot() reads $_SERVER first, then $_ENV,
        // then falls back to 'UTC'. The OS-level env (getenv/putenv) is unset for
        // belt-and-braces in case future code switches to it.
        unset($_SERVER['APP_TIMEZONE'], $_ENV['APP_TIMEZONE']);
        putenv('APP_TIMEZONE');
        // Pretend the previous boot left a non-UTC tz behind.
        date_default_timezone_set('Asia/Tokyo');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $this->assertSame('UTC', date_default_timezone_get());
        $kernel->shutdown();
    }

    private function setAppTimezone(string $value): void
    {
        $_SERVER['APP_TIMEZONE'] = $value;
        $_ENV['APP_TIMEZONE'] = $value;
        putenv('APP_TIMEZONE=' . $value);
    }

    private function restoreEnv(string $name, mixed $serverPrev, mixed $envPrev, string|false $getenvPrev): void
    {
        if ($serverPrev === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $serverPrev;
        }
        if ($envPrev === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $envPrev;
        }
        if ($getenvPrev === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $getenvPrev);
        }
    }
}
