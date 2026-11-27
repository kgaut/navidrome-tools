<?php

namespace App\Tests\Controller;

use App\Security\EnvUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #310: the history pages moved from /lastfm/… to /listens/…; old
 * bookmarks get a permanent redirect with their query string.
 */
class LegacyHistoryRoutesControllerTest extends WebTestCase
{
    public function testOldPathsRedirectPermanentlyKeepingTheQuery(): void
    {
        $client = static::createClient();
        $client->loginUser(new EnvUser('admin', 'x'));

        foreach ([
            '/lastfm/stats' => '/listens/stats',
            '/lastfm/top-artists?period=all' => '/listens/top-artists?period=all',
            '/lastfm/scrobbles?year=2025' => '/listens?year=2025',
            '/lastfm/import' => '/listens/import',
        ] as $old => $new) {
            $client->request('GET', $old);

            $this->assertSame(301, $client->getResponse()->getStatusCode(), $old);
            $this->assertSame($new, $client->getResponse()->headers->get('Location'), $old);
        }
    }
}
