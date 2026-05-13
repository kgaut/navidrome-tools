<?php

namespace App\Notifier;

interface NotifierDriverInterface
{
    /**
     * Stable identifier used by NOTIFY_DRIVERS to enable this driver.
     * Lowercase, no spaces (e.g. "gotify", "slack", "discord", "pushover").
     */
    public function getName(): string;

    /**
     * True only when the driver has the credentials it needs to send.
     * False = the orchestrator silently skips it (typical when the env
     * vars are empty).
     */
    public function isConfigured(): bool;

    /**
     * Push the notification synchronously. Drivers MAY throw on transport
     * failure ; the orchestrator catches and logs so one broken driver
     * never blocks the others.
     */
    public function send(Notification $notification): void;
}
