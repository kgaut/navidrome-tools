<?php

declare(strict_types=1);

namespace App\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Stores and reads `DateTimeImmutable` values as UTC, regardless of the PHP
 * default timezone in effect.
 *
 * Doctrine's built-in `datetime_immutable` writes the wall-clock string in
 * the value's current timezone and reads it back tagged with PHP's default
 * timezone. With `APP_TIMEZONE=Europe/Paris`, that round-trip silently
 * shifts an UTC instant by the offset (a `2026-05-03 10:00:00 UTC` value
 * becomes `2026-05-03 10:00:00 Europe/Paris` on read — same wall clock,
 * different instant). Twig's `|date` filter, configured with the same
 * timezone, then renders the UTC clock value as if it were local.
 *
 * This type forces both directions to UTC so the stored wall clock is
 * always UTC and the loaded `DateTimeImmutable` is always UTC-tagged,
 * leaving Twig (and any downstream code) free to convert to the display
 * timezone correctly.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'utc_datetime_immutable';

    private static ?DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            $platform->getDateTimeFormatString(),
            $value,
            self::utc(),
        );

        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new DateTimeImmutable($value, self::utc());
        } catch (\Exception $e) {
            throw InvalidFormat::new(
                $value,
                static::class,
                $platform->getDateTimeFormatString(),
                $e,
            );
        }
    }

    private static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }
}
