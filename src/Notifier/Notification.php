<?php

namespace App\Notifier;

use App\Entity\RunHistory;

/**
 * Payload pushed to every configured notification driver after a
 * RunHistoryRecorder-wrapped job finishes. Holds the minimum a human
 * needs to triage the run from a phone notification (type/label/status/
 * duration/error) without consulting the UI — plus the run id so a
 * driver can include a deep link to /history/{id}.
 */
final class Notification
{
    /** @param array<string, mixed>|null $metrics */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $status,
        public readonly int $durationMs,
        public readonly ?int $runId = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $metrics = null,
    ) {
    }

    public static function fromRunHistory(RunHistory $run): self
    {
        return new self(
            type: $run->getType(),
            label: $run->getLabel(),
            status: $run->getStatus(),
            durationMs: $run->getDurationMs() ?? 0,
            runId: $run->getId(),
            errorMessage: $run->getStatus() === RunHistory::STATUS_ERROR ? $run->getMessage() : null,
            metrics: $run->getMetrics(),
        );
    }

    public function isError(): bool
    {
        return $this->status === RunHistory::STATUS_ERROR;
    }

    public function title(): string
    {
        $prefix = $this->isError() ? '[ERROR]' : '[OK]';

        return sprintf('%s %s', $prefix, $this->label);
    }

    public function summary(): string
    {
        $lines = [
            sprintf('Type: %s', $this->type),
            sprintf('Status: %s', $this->status),
            sprintf('Duration: %s', self::formatDuration($this->durationMs)),
        ];

        if ($this->errorMessage !== null && $this->errorMessage !== '') {
            $lines[] = sprintf('Error: %s', self::truncate($this->errorMessage, 500));
        }

        if (!empty($this->metrics)) {
            $pairs = [];
            foreach ($this->metrics as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $pairs[] = sprintf('%s=%s', $key, self::scalarToString($value));
                }
            }
            if ($pairs !== []) {
                $lines[] = 'Metrics: ' . implode(' ', $pairs);
            }
        }

        return implode("\n", $lines);
    }

    private static function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        $s = $ms / 1000;
        if ($s < 60) {
            return sprintf('%.1fs', $s);
        }
        $m = (int) floor($s / 60);
        $rest = $s - ($m * 60);

        return sprintf('%dm%02ds', $m, (int) round($rest));
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max) . '…';
    }

    private static function scalarToString(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }

        return (string) $v;
    }
}
