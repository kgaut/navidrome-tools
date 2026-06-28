<?php

namespace App\Tests\Playlist;

use App\Playlist\PlaylistEnablement;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;

class PlaylistEnablementTest extends TestCase
{
    public function testDefaultsToEnabledWhenNoSetting(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        // Unset → repository returns the provided default ('1').
        $settings->method('get')->willReturnCallback(fn (string $k, string $d = '') => $d);

        $this->assertTrue((new PlaylistEnablement($settings))->isEnabled('kickstart'));
    }

    public function testReadsStoredDisabledFlag(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->with('playlist.enabled.kickstart', '1')->willReturn('0');

        $this->assertFalse((new PlaylistEnablement($settings))->isEnabled('kickstart'));
    }

    public function testSetEnabledPersistsCanonicalValue(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, string $value): void {
                $this->assertSame('playlist.enabled.hit-parade', $key);
                $this->assertContains($value, ['0', '1']);
            });

        $e = new PlaylistEnablement($settings);
        $e->setEnabled('hit-parade', true);
        $e->setEnabled('hit-parade', false);
    }
}
