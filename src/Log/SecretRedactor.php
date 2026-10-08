<?php

namespace App\Log;

/**
 * Masks credentials carried in URL query strings before they reach logs,
 * run history or notifications (issue #265).
 *
 * Subsonic authenticates every call with `t` (md5(password + salt)) and
 * `s` (salt): that pair replays as-is for the whole API until the password
 * changes. Last.fm GET calls carry `api_key`, signed ones `api_sig` / `sk`.
 */
final class SecretRedactor
{
    /** Query parameters whose value is a credential. */
    private const PARAMS = ['t', 's', 'p', 'password', 'api_key', 'api_sig', 'sk', 'token', 'apikey', 'api_token'];

    private const MASK = '***';

    public static function redact(string $text): string
    {
        $names = implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), self::PARAMS));

        return (string) preg_replace('/([?&](?:' . $names . ')=)[^&#\s"\'<>]*/i', '$1' . self::MASK, $text);
    }

    /**
     * Redacts every string found in a log context (recursively); objects
     * other than \Stringable are left untouched.
     *
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    public static function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = self::redact($value);
            } elseif ($value instanceof \Stringable && !$value instanceof \Throwable) {
                $context[$key] = self::redact((string) $value);
            } elseif (is_array($value)) {
                $context[$key] = self::redactContext($value);
            }
        }

        return $context;
    }
}
