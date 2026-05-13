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

    public function testDescribeDriversReturnsListedAndConfiguredFlags(): void
    {
        $gotify = new FakeRecordingDriver('gotify');
        $slack = new FakeRecordingDriver('slack', configured: false);
        $discord = new FakeRecordingDriver('discord');

        $notifier = new Notifier([$gotify, $slack, $discord], 'gotify,slack', Notifier::ON_ERROR);

        $this->assertSame([
            ['name' => 'gotify', 'listed' => true, 'configured' => true],
            ['name' => 'slack', 'listed' => true, 'configured' => false],
            ['name' => 'discord', 'listed' => false, 'configured' => true],
        ], $notifier->describeDrivers());

        $this->assertSame('error', $notifier->getNotifyOn());
    }

    public function testTestSendBypassesNotifyOnFilterAndReportsPerDriver(): void
    {
        $gotify = new FakeRecordingDriver('gotify');
        $slack = new FakeRecordingDriver('slack', configured: false);
        $discord = new FakeRecordingDriver('discord');
        $broken = new FakeRecordingDriver('pushover');
        $broken->shouldThrow = true;

        // NOTIFY_ON=error : a successNotification() would normally be
        // filtered, but testSend() must dispatch anyway.
        $notifier = new Notifier(
            [$gotify, $slack, $discord, $broken],
            'gotify,slack,pushover',
            Notifier::ON_ERROR,
        );

        $result = $notifier->testSend($this->successNotification());

        $this->assertSame(1, $gotify->sendCount);
        $this->assertSame(0, $slack->sendCount, 'unconfigured drivers must not be called');
        $this->assertSame(0, $discord->sendCount, 'drivers absent from NOTIFY_DRIVERS must not be called');
        $this->assertSame(1, $broken->sendCount);

        $this->assertSame('sent', $result['gotify']);
        $this->assertSame('skipped:not-configured', $result['slack']);
        $this->assertSame('skipped:not-listed', $result['discord']);
        $this->assertStringStartsWith('error:', $result['pushover']);
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
