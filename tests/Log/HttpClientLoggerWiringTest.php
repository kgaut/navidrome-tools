<?php

namespace App\Tests\Log;

use App\Log\RedactingLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The framework HTTP client must log through the redacting decorator (issue #265). */
class HttpClientLoggerWiringTest extends KernelTestCase
{
    public function testHttpClientTransportUsesRedactingLogger(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('http_client.transport');

        $logger = (new \ReflectionProperty($transport, 'logger'))->getValue($transport);

        $this->assertInstanceOf(RedactingLogger::class, $logger);
    }
}
