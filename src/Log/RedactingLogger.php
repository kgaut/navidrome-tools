<?php

namespace App\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Logger decorator that masks URL credentials (issue #265). Given to
 * Symfony's HTTP client, which logs every request URL at `info` (shown by
 * the `-vv` worker): Subsonic `t`/`s` tokens, Last.fm `api_key`…
 * Wired in {@see \App\Kernel::process()}.
 */
final class RedactingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, SecretRedactor::redact((string) $message), SecretRedactor::redactContext($context));
    }
}
