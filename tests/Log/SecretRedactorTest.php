<?php

namespace App\Tests\Log;

use App\Log\RedactingLogger;
use App\Log\SecretRedactor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class SecretRedactorTest extends TestCase
{
    public function testMasksSubsonicTokenSaltAndPassword(): void
    {
        $url = 'http://navidrome:4533/rest/createPlaylist.view?u=kevin&t=5f4dcc3b5aa765d61d8327deb882cf99&s=abc123&p=enc:6869&v=1.16.1&c=navidrome-tools&f=json&name=Mix&songId=a&songId=b';

        $this->assertSame(
            'http://navidrome:4533/rest/createPlaylist.view?u=kevin&t=***&s=***&p=***&v=1.16.1&c=navidrome-tools&f=json&name=Mix&songId=a&songId=b',
            SecretRedactor::redact($url),
        );
    }

    public function testMasksLastFmAndGenericKeysInsideMessages(): void
    {
        $message = 'HTTP 500 returned for "https://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&API_KEY=deadbeef&sk=sess&api_sig=sig&user=kevin&page=2".';

        $this->assertSame(
            'HTTP 500 returned for "https://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&API_KEY=***&sk=***&api_sig=***&user=kevin&page=2".',
            SecretRedactor::redact($message),
        );
    }

    public function testLeavesLookalikeParametersAndPlainTextAlone(): void
    {
        $this->assertSame('?songs=12&ts=3&sort=asc', SecretRedactor::redact('?songs=12&ts=3&sort=asc'));
        $this->assertSame('t=1 is not a query string', SecretRedactor::redact('t=1 is not a query string'));
    }

    public function testRedactingLoggerMasksMessageAndContext(): void
    {
        $inner = new class () extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        (new RedactingLogger($inner))->info('Request: "{method} {url}"', [
            'method' => 'GET',
            'url' => 'http://nd/rest/ping.view?u=me&t=tok&s=salt',
            'nested' => ['url' => 'http://nd/?token=x'],
        ]);

        $this->assertSame('Request: "{method} {url}"', $inner->records[0][0]);
        $this->assertSame('http://nd/rest/ping.view?u=me&t=***&s=***', $inner->records[0][1]['url']);
        $this->assertSame('http://nd/?token=***', $inner->records[0][1]['nested']['url']);
    }
}
