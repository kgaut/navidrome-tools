<?php

namespace App\LastFm;

use Doctrine\DBAL\Connection;

/**
 * Streams scrobbles from the Last.fm API and stores them in the local
 * lastfm_import_buffer table. No matching, no Navidrome write — safe to
 * run while Navidrome is up.
 *
 * Idempotency comes from the unique constraint on
 * (lastfm_user, played_at, artist, title) plus an INSERT OR IGNORE.
 * Re-fetching a window already present in the buffer is a no-op.
 *
 * The actual matching + insertion into Navidrome happens later via
 * {@see LastFmBufferProcessor}.
 */
class LastFmFetcher
{
    public function __construct(
        private readonly LastFmClient $client,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param callable(int $fetched, int $buffered, int $alreadyBuffered, ?\DateTimeImmutable $batchFirstPlayedAt, ?\DateTimeImmutable $batchLastPlayedAt): void $progress
     */
    public function fetch(
        string $apiKey,
        string $lastFmUser,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
        ?int $maxScrobbles = null,
        bool $dryRun = false,
        ?callable $progress = null,
    ): FetchReport {
        $report = new FetchReport();
        $utc = new \DateTimeZone('UTC');
        $now = (new \DateTimeImmutable('now'))->setTimezone($utc)->format('Y-m-d H:i:s');
        $batchFirstPlayedAt = null;
        $batchLastPlayedAt = null;

        foreach ($this->client->streamRecentTracks($apiKey, $lastFmUser, $dateMin, $dateMax) as $scrobble) {
            if ($maxScrobbles !== null && $report->fetched >= $maxScrobbles) {
                break;
            }
            $report->fetched++;

            $scrobblePlayedAt = $scrobble->playedAt->setTimezone($utc);
            if ($batchFirstPlayedAt === null) {
                $batchFirstPlayedAt = $scrobblePlayedAt;
            }
            $batchLastPlayedAt = $scrobblePlayedAt;

            if ($dryRun) {
                if ($progress !== null && $report->fetched % 50 === 0) {
                    $progress($report->fetched, $report->buffered, $report->alreadyBuffered, $batchFirstPlayedAt, $batchLastPlayedAt);
                    $batchFirstPlayedAt = null;
                    $batchLastPlayedAt = null;
                }
                continue;
            }

            $playedAt = $scrobblePlayedAt->format('Y-m-d H:i:s');
            $album = $scrobble->album !== '' ? $scrobble->album : null;

            // INSERT OR IGNORE leans on the SQLite unique constraint to drop
            // duplicates without raising — rowCount() tells us whether the
            // row landed (1) or was rejected (0).
            $affected = $this->connection->executeStatement(
                'INSERT OR IGNORE INTO lastfm_import_buffer '
                . '(lastfm_user, artist, title, album, mbid, played_at, fetched_at) '
                . 'VALUES (:lastfm_user, :artist, :title, :album, :mbid, :played_at, :fetched_at)',
                [
                    'lastfm_user' => $lastFmUser,
                    'artist' => $scrobble->artist,
                    'title' => $scrobble->title,
                    'album' => $album,
                    'mbid' => $scrobble->mbid,
                    'played_at' => $playedAt,
                    'fetched_at' => $now,
                ],
            );

            if ($affected === 1) {
                $report->buffered++;
            } else {
                $report->alreadyBuffered++;
            }

            if ($progress !== null && $report->fetched % 50 === 0) {
                $progress($report->fetched, $report->buffered, $report->alreadyBuffered, $batchFirstPlayedAt, $batchLastPlayedAt);
                $batchFirstPlayedAt = null;
                $batchLastPlayedAt = null;
            }
        }

        if ($progress !== null) {
            $progress($report->fetched, $report->buffered, $report->alreadyBuffered, $batchFirstPlayedAt, $batchLastPlayedAt);
        }

        return $report;
    }
}
