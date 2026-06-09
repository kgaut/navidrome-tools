<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeStringNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canonical forms produced by the string normalizer (extracted
 * from NavidromeRepository). Behaviour is preserved bit-for-bit by the
 * extraction — these tests will catch any future drift on the
 * normalization rules that the matching cascade and 31+ external callers
 * depend on.
 */
class NavidromeStringNormalizerTest extends TestCase
{
    public function testNormalizeLowercasesAndStripsAccents(): void
    {
        $this->assertSame('beyonce', NavidromeStringNormalizer::normalize('Beyoncé'));
        $this->assertSame('sigur ros', NavidromeStringNormalizer::normalize('Sigur Rós'));
    }

    public function testNormalizeStripsPunctuationButKeepsLettersDigitsSpace(): void
    {
        // Punctuation is dropped without inserting whitespace — that's the
        // contract that keeps `np_normalize('AC/DC')` and `np_normalize('ACDC')`
        // collide on the same key in SQLite-side joins.
        $this->assertSame('acdc', NavidromeStringNormalizer::normalize('AC/DC'));
        $this->assertSame('54', NavidromeStringNormalizer::normalize('5/4'));
        $this->assertSame('get lucky feat pharrell williams', NavidromeStringNormalizer::normalize('Get Lucky (feat. Pharrell Williams)'));
    }

    public function testNormalizeCollapsesInternalWhitespaceAndTrims(): void
    {
        $this->assertSame('the trimmed', NavidromeStringNormalizer::normalize('  The   trimmed  '));
        $this->assertSame('the trimmed', NavidromeStringNormalizer::normalize("  The\t  trimmed\n  "));
    }

    public function testStripFeaturedArtistsHandlesBothParenAndTrailingForms(): void
    {
        $this->assertSame('Daft Punk', NavidromeStringNormalizer::stripFeaturedArtists('Daft Punk (feat. Pharrell)'));
        $this->assertSame('Daft Punk', NavidromeStringNormalizer::stripFeaturedArtists('Daft Punk ft. Pharrell'));
        $this->assertSame('Daft Punk', NavidromeStringNormalizer::stripFeaturedArtists('Daft Punk featuring Pharrell'));
        $this->assertSame('Daft Punk', NavidromeStringNormalizer::stripFeaturedArtists('Daft Punk'));
    }

    public function testStripVersionMarkersHandlesParenBracketAndDashForms(): void
    {
        $this->assertSame('Bohemian Rhapsody', NavidromeStringNormalizer::stripVersionMarkers('Bohemian Rhapsody (Remastered 2011)'));
        $this->assertSame('Bohemian Rhapsody', NavidromeStringNormalizer::stripVersionMarkers('Bohemian Rhapsody [Remastered]'));
        $this->assertSame('Bohemian Rhapsody', NavidromeStringNormalizer::stripVersionMarkers('Bohemian Rhapsody - Remastered 2011'));
        $this->assertSame('Around the World', NavidromeStringNormalizer::stripVersionMarkers('Around the World (Radio Edit)'));
    }

    public function testStripVersionMarkersLeavesDelimitedLiveAloneInTitleBody(): void
    {
        // "Live and Let Die" must not lose its first word — the « live »
        // marker is only stripped when it's a *trailing* delimited suffix.
        $this->assertSame('Live and Let Die', NavidromeStringNormalizer::stripVersionMarkers('Live and Let Die'));
        $this->assertSame('Live and Let Die', NavidromeStringNormalizer::stripVersionMarkers('Live and Let Die (Remastered)'));
    }

    public function testStripVersionMarkersDoesNotStripRemix(): void
    {
        // DJ remixes are usually distinct recordings — must NOT be stripped.
        $this->assertSame('Around the World (Daft Punk Remix)', NavidromeStringNormalizer::stripVersionMarkers('Around the World (Daft Punk Remix)'));
    }

    public function testStripFeaturingFromTitleOnlyDelimited(): void
    {
        $this->assertSame('Crazy in Love', NavidromeStringNormalizer::stripFeaturingFromTitle('Crazy in Love (feat. Jay-Z)'));
        $this->assertSame('Bad Guy', NavidromeStringNormalizer::stripFeaturingFromTitle('Bad Guy [with Justin Bieber]'));
        // No parens → never stripped.
        $this->assertSame('Some Track feat. X', NavidromeStringNormalizer::stripFeaturingFromTitle('Some Track feat. X'));
    }

    public function testStripTrackNumberPrefixDropsLeadingDigits(): void
    {
        $this->assertSame('Around the World', NavidromeStringNormalizer::stripTrackNumberPrefix('01 - Around the World'));
        $this->assertSame('Around the World', NavidromeStringNormalizer::stripTrackNumberPrefix('02_Around the World'));
        $this->assertSame('Around the World', NavidromeStringNormalizer::stripTrackNumberPrefix('100. Around the World'));
    }

    public function testStripTrackNumberPrefixLeavesStandaloneNumericTitlesAlone(): void
    {
        $this->assertSame('1979', NavidromeStringNormalizer::stripTrackNumberPrefix('1979'));
        $this->assertSame('5/4', NavidromeStringNormalizer::stripTrackNumberPrefix('5/4'));
    }

    public function testStripTruncatedParenOnlyWhenUnbalancedAndMarkerKnown(): void
    {
        $this->assertSame('Crazy in Love', NavidromeStringNormalizer::stripTruncatedParen('Crazy in Love (feat. Jay'));
        // Closed paren → never stripped, even with marker.
        $this->assertSame('Crazy in Love (feat. Jay-Z)', NavidromeStringNormalizer::stripTruncatedParen('Crazy in Love (feat. Jay-Z)'));
        // Unbalanced but unknown marker → kept (conservative).
        $this->assertSame('Foo (something', NavidromeStringNormalizer::stripTruncatedParen('Foo (something'));
    }

    public function testStripLeadArtistKeepsOnlyTheLeadOnRecognizedSeparators(): void
    {
        $this->assertSame('Médine', NavidromeStringNormalizer::stripLeadArtist('Médine & Rounhaa'));
        $this->assertSame('Foo', NavidromeStringNormalizer::stripLeadArtist('Foo, Bar'));
        $this->assertSame('Foo', NavidromeStringNormalizer::stripLeadArtist('Foo and Bar'));
        // No separator → unchanged.
        $this->assertSame('Lone Artist', NavidromeStringNormalizer::stripLeadArtist('Lone Artist'));
    }

    public function testTitleHasFeaturingMarkerCoversClosedAndTruncatedForms(): void
    {
        $this->assertTrue(NavidromeStringNormalizer::titleHasFeaturingMarker('Crazy in Love (feat. Jay-Z)'));
        $this->assertTrue(NavidromeStringNormalizer::titleHasFeaturingMarker('Bad Guy [with Justin Bieber]'));
        $this->assertTrue(NavidromeStringNormalizer::titleHasFeaturingMarker('Crazy in Love (feat. Jay')); // truncated
        $this->assertFalse(NavidromeStringNormalizer::titleHasFeaturingMarker('Crazy in Love'));
        // Closed but unrelated paren content → not a featuring marker.
        $this->assertFalse(NavidromeStringNormalizer::titleHasFeaturingMarker('Around the World (Radio Edit)'));
    }

    public function testNavidromeRepositoryShimStillWorks(): void
    {
        // Backward compat: NavidromeRepository::normalize() must keep
        // returning the same value as the new class, so the 31 external
        // callers don't need to migrate atomically.
        $this->assertSame(
            NavidromeStringNormalizer::normalize('Sigur Rós'),
            \App\Navidrome\NavidromeRepository::normalize('Sigur Rós'),
        );
    }
}
