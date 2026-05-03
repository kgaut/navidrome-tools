<?php

namespace App\Tests\Doctrine;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

class UtcDateTimeImmutableTypeTest extends TestCase
{
    private SQLitePlatform $platform;
    private UtcDateTimeImmutableType $type;
    private string $previousTimezone = 'UTC';

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        if (!Type::hasType(UtcDateTimeImmutableType::NAME)) {
            Type::addType(UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::class);
        }
        $type = Type::getType(UtcDateTimeImmutableType::NAME);
        $this->assertInstanceOf(UtcDateTimeImmutableType::class, $type);
        $this->type = $type;
        $this->platform = new SQLitePlatform();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
    }

    public function testWritesUtcWallClockEvenWhenValueIsLocal(): void
    {
        // 12:00 in Paris is 10:00 UTC; we want the on-disk string to be 10:00.
        $paris = new \DateTimeImmutable('2026-05-03 12:00:00', new \DateTimeZone('Europe/Paris'));
        $this->assertSame(
            '2026-05-03 10:00:00',
            $this->type->convertToDatabaseValue($paris, $this->platform),
        );
    }

    public function testReadBackPreservesInstantAcrossPhpDefaultTimezone(): void
    {
        // Simulate the bug condition: PHP default tz is non-UTC at read time.
        date_default_timezone_set('Europe/Paris');
        $loaded = $this->type->convertToPHPValue('2026-05-03 10:00:00', $this->platform);
        $this->assertNotNull($loaded);
        $this->assertSame('UTC', $loaded->getTimezone()->getName());
        // Same wall-clock interpreted as UTC, not Paris.
        $this->assertSame('2026-05-03T10:00:00+00:00', $loaded->format(\DATE_ATOM));
    }

    public function testRoundTripIsIdempotent(): void
    {
        date_default_timezone_set('Europe/Paris');
        $original = new \DateTimeImmutable('2026-05-03 12:00:00', new \DateTimeZone('Europe/Paris'));
        $stored = $this->type->convertToDatabaseValue($original, $this->platform);
        $loaded = $this->type->convertToPHPValue($stored, $this->platform);
        $this->assertNotNull($loaded);
        // Same instant, expressed in UTC after the round-trip.
        $this->assertSame($original->getTimestamp(), $loaded->getTimestamp());
        $this->assertSame('UTC', $loaded->getTimezone()->getName());
    }

    public function testNullPassthrough(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
