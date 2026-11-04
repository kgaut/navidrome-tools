<?php

namespace App\Tests\Docker;

use App\Docker\ContainerStatus;
use App\Docker\DockerCli;
use App\Docker\NavidromeContainerConfig;
use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Docker\NavidromeLiveness;
use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #296: with NAVIDROME_CONTAINER_NAME empty, every write guard used to
 * let writes through, Navidrome running or not.
 */
class NavidromeWriteGuardTest extends TestCase
{
    public function testLivenessAnyHttpAnswerMeansUp(): void
    {
        $this->assertTrue((new NavidromeLiveness(new MockHttpClient(new MockResponse('', ['http_code' => 401])), 'http://navidrome:4533'))->isUp());
        $this->assertFalse((new NavidromeLiveness(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])), 'http://navidrome:4533'))->isUp());
        $this->assertNull((new NavidromeLiveness(new MockHttpClient(), ''))->isUp());
    }

    public function testUnmanagedNavidromeThatAnswersBlocksWrites(): void
    {
        $manager = $this->manager(up: true);

        $this->assertSame(ContainerStatus::Running, $manager->getStatus());
        $this->expectException(NavidromeContainerException::class);
        try {
            $manager->assertSafeToWrite();
        } finally {
            $ran = false;
            try {
                $manager->runWithNavidromeStopped(function () use (&$ran): void {
                    $ran = true;
                });
            } catch (NavidromeContainerException) {
            }
            $this->assertFalse($ran, 'the write must not run while Navidrome answers');
        }
    }

    public function testUnmanagedNavidromeThatIsDownRunsTheActionWithABackup(): void
    {
        $backup = $this->createMock(NavidromeDbBackup::class);
        $backup->expects($this->once())->method('backup');
        $manager = $this->manager(up: false, backup: $backup);

        $this->assertSame(ContainerStatus::Disabled, $manager->getStatus());
        $manager->assertSafeToWrite();
        $this->assertSame('done', $manager->runWithNavidromeStopped(static fn () => 'done'));
    }

    private function manager(bool $up, ?NavidromeDbBackup $backup = null): NavidromeContainerManager
    {
        $response = $up ? new MockResponse('{}', ['http_code' => 200]) : new MockResponse('', ['error' => 'Connection refused']);

        return new NavidromeContainerManager(
            $this->createMock(DockerCli::class),
            new NavidromeContainerConfig(''),
            $backup ?? $this->createMock(NavidromeDbBackup::class),
            new NavidromeLiveness(new MockHttpClient(static fn () => clone $response), 'http://navidrome:4533'),
        );
    }
}
