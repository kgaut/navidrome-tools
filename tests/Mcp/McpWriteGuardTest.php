<?php

namespace App\Tests\Mcp;

use App\Mcp\McpWriteGuard;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class McpWriteGuardTest extends TestCase
{
    public function testPassesWithWriteRole(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_OAUTH2_MCP_WRITE')->willReturn(true);

        (new McpWriteGuard($security))->assertCanWrite();
        $this->addToAssertionCount(1);
    }

    public function testRefusesWithoutWriteRole(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('mcp_write');
        (new McpWriteGuard($security))->assertCanWrite();
    }
}
