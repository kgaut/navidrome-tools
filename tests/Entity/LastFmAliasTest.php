<?php

namespace App\Tests\Entity;

use App\Entity\LastFmAlias;
use PHPUnit\Framework\TestCase;

class LastFmAliasTest extends TestCase
{
    public function testConstructorNormalizesSourceFields(): void
    {
        $a = new LastFmAlias('Beyoncé', 'Halo (Live)', 'mf-1');

        $this->assertSame('Beyoncé', $a->getSourceArtist());
        $this->assertSame('Halo (Live)', $a->getSourceTitle());
        // Normalized form: lowercase + accents stripped + punctuation stripped + collapsed spaces.
        $this->assertSame('beyonce', $a->getSourceArtistNorm());
        $this->assertSame('halo live', $a->getSourceTitleNorm());
        $this->assertSame('mf-1', $a->getTargetMediaFileId());
        $this->assertFalse($a->isSkip());
    }

    public function testNullTargetMeansSkip(): void
    {
        $a = new LastFmAlias('Some Podcast', 'Episode 42', null);
        $this->assertTrue($a->isSkip());
        $this->assertNull($a->getTargetMediaFileId());
    }

    public function testEmptyTargetIsCoercedToSkip(): void
    {
        $a = new LastFmAlias('Some Podcast', 'Episode 42', '');
        $this->assertTrue($a->isSkip());
        $this->assertNull($a->getTargetMediaFileId());
    }

    public function testSetSourceRecomputesNormalization(): void
    {
        $a = new LastFmAlias('Old Artist', 'Old Title', 'mf-1');
        $a->setSource('Café Tacvba', 'La Ingrata');

        $this->assertSame('Café Tacvba', $a->getSourceArtist());
        $this->assertSame('cafe tacvba', $a->getSourceArtistNorm());
        $this->assertSame('la ingrata', $a->getSourceTitleNorm());
    }

    public function testSetTargetMediaFileIdAcceptsNullAndString(): void
    {
        $a = new LastFmAlias('A', 'B', 'mf-1');
        $a->setTargetMediaFileId(null);
        $this->assertTrue($a->isSkip());
        $a->setTargetMediaFileId('mf-2');
        $this->assertSame('mf-2', $a->getTargetMediaFileId());
        $a->setTargetMediaFileId(''); // empty string also coerces to skip
        $this->assertTrue($a->isSkip());
    }
}
