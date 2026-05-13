<?php

namespace App\Tests\Notifier;

use App\Notifier\Notification;
use App\Notifier\NotifierDriverInterface;

/**
 * Test double recording every call to send() — used across Notifier tests
 * to assert which drivers were dispatched and with which payload.
 */
final class FakeRecordingDriver implements NotifierDriverInterface
{
    public int $sendCount = 0;
    public ?Notification $lastNotification = null;
    public bool $shouldThrow = false;

    public function __construct(
        private readonly string $name,
        private readonly bool $configured = true,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(Notification $notification): void
    {
        ++$this->sendCount;
        $this->lastNotification = $notification;
        if ($this->shouldThrow) {
            throw new \RuntimeException('boom');
        }
    }
}
