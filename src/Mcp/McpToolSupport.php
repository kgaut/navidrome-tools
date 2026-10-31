<?php

namespace App\Mcp;

use App\Exception\UserFacingException;
use Mcp\Exception\ToolCallException;

/**
 * Helpers shared by the MCP tools (issue #276).
 */
trait McpToolSupport
{
    /**
     * Runs a tool body: a {@see UserFacingException} becomes a readable tool
     * error (`isError`), anything else stays an internal error, logged by
     * the SDK.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    private function refusable(callable $body): mixed
    {
        try {
            return $body();
        } catch (UserFacingException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Brings a size argument back into [1, $max]: beyond the cap it is
     * lowered, not refused; the effective value is returned so the tool can
     * report it.
     */
    private static function clamp(int $value, int $max): int
    {
        return max(1, min($max, $value));
    }

    /**
     * Parses an optional YYYY-MM-DD day (local time).
     *
     * @throws ToolCallException when malformed
     */
    private static function parseDay(?string $value, string $field, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz)
            : false;
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new ToolCallException(sprintf('« %s » invalide : date attendue au format AAAA-MM-JJ.', $field));
        }

        return $day;
    }

    /**
     * Message line for a capped list.
     */
    private static function limitNote(int $served, int $limit, ?int $total = null): string
    {
        if ($total !== null) {
            return $served >= $limit && $total > $served
                ? sprintf('%d résultat(s) servi(s) sur %d : limite atteinte (%d), paginer avec offset.', $served, $total, $limit)
                : sprintf('%d résultat(s) sur %d.', $served, $total);
        }

        return $served >= $limit
            ? sprintf('%d résultat(s) : limite atteinte (%d).', $served, $limit)
            : sprintf('%d résultat(s).', $served);
    }
}
