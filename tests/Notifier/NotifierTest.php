<?php

namespace App\Tests\Notifier;

use App\Entity\RunHistory;
use App\Notifier\Notification;
use App\Notifier\Notifier;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    public function testEmptyCsvSkipsAllDrivers(): void
    {
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], '', Notifier::ON_ALL);

        $notifier->notify($this->errorNotification());

        $this->assertSame(0, $driver->sendCount);
        $this->assertFalse($notifier->isEnabled());
    }

    public function testNotifyOnErrorSkipsSuccessRuns(): void
    {
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ERROR);

        $notifier->notify($this->successNotification());

        $this->assertSame(0, $driver->sendCount, 'success runs must be filtered out when NOTIFY_ON=error');
        $this->assertTrue($notifier->isEnabled());
    }

    public function testNotifyOnErrorStillSendsErrors(): void
    {
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ERROR);

        $notifier->notify($this->errorNotification());

        $this->assertSame(1, $driver->sendCount);
    }

    public function testNotifyOnAllForwardsBothSuccessAndError(): void
    {
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ALL);

        $notifier->notify($this->successNotification());
        $notifier->notify($this->errorNotification());

        $this->assertSame(2, $driver->sendCount);
    }

    public function testMultiDriverCsvFansOutAndIgnoresUnknownNames(): void
    {
        $gotify = new FakeRecordingDriver('gotify');
        $slack = new FakeRecordingDriver('slack');
        $discord = new FakeRecordingDriver('discord');

        $notifier = new Notifier([$gotify, $slack, $discord], 'gotify, slack , bogus', Notifier::ON_ALL);
        $notifier->notify($this->successNotification());

        $this->assertSame(1, $gotify->sendCount);
        $this->assertSame(1, $slack->sendCount);
        $this->assertSame(0, $discord->sendCount, 'Driver not listed in CSV must be skipped');
    }

    public function testDriverListedButUnconfiguredIsSkipped(): void
    {
        $driver = new FakeRecordingDriver('gotify', configured: false);
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ALL);

        $notifier->notify($this->errorNotification());

        $this->assertSame(0, $driver->sendCount);
    }

    public function testOneDriverThrowingDoesNotBreakSiblings(): void
    {
        $broken = new FakeRecordingDriver('gotify');
        $broken->shouldThrow = true;
        $slack = new FakeRecordingDriver('slack');

        $notifier = new Notifier([$broken, $slack], 'gotify,slack', Notifier::ON_ALL);
        $notifier->notify($this->errorNotification());

        $this->assertSame(1, $broken->sendCount);
        $this->assertSame(1, $slack->sendCount, 'Slack must still receive the notification despite Gotify failing');
    }

    private function successNotification(): Notification
    {
        return new Notification('lastfm-fetch', 'Last.fm fetch — me', RunHistory::STATUS_SUCCESS, 1000);
    }

    private function errorNotification(): Notification
    {
        return new Notification('lastfm-fetch', 'Last.fm fetch — me', RunHistory::STATUS_ERROR, 1000, errorMessage: 'boom');
    }
}
