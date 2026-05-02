<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmApiSigner;
use PHPUnit\Framework\TestCase;

class LastFmApiSignerTest extends TestCase
{
    public function testSignSortsParamsAlphabeticallyAndAppendsSecret(): void
    {
        // Reference computation per https://www.last.fm/api/authspec :
        //   sorted = api_keyXXX + methodauth.getSession + tokenABC
        //   md5(sorted . secret)
        $expected = md5('api_keyXXXmethodauth.getSessiontokenABCsecret');

        $sig = LastFmApiSigner::sign([
            'token' => 'ABC',
            'method' => 'auth.getSession',
            'api_key' => 'XXX',
        ], 'secret');

        $this->assertSame($expected, $sig);
    }

    public function testSignDropsFormatAndCallback(): void
    {
        // `format` and `callback` MUST be excluded before signing per spec.
        $withExtras = LastFmApiSigner::sign([
            'method' => 'auth.getSession',
            'api_key' => 'XXX',
            'token' => 'ABC',
            'format' => 'json',
            'callback' => 'http://example.test/cb',
        ], 'secret');
        $withoutExtras = LastFmApiSigner::sign([
            'method' => 'auth.getSession',
            'api_key' => 'XXX',
            'token' => 'ABC',
        ], 'secret');

        $this->assertSame($withoutExtras, $withExtras);
    }

    public function testSignIsStableRegardlessOfInsertionOrder(): void
    {
        $a = LastFmApiSigner::sign([
            'method' => 'track.love',
            'api_key' => 'KEY',
            'artist' => 'Beyoncé',
            'sk' => 'SESSION',
            'track' => 'Halo',
        ], 'secret');
        $b = LastFmApiSigner::sign([
            'sk' => 'SESSION',
            'track' => 'Halo',
            'api_key' => 'KEY',
            'artist' => 'Beyoncé',
            'method' => 'track.love',
        ], 'secret');

        $this->assertSame($a, $b);
    }
}
