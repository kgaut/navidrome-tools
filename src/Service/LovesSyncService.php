<?php

namespace App\Service;

use App\LastFm\LastFmClient;
use App\LastFm\LovesSyncReport;
use App\Navidrome\NavidromeRepository;

/**
 * Bidirectional sync of « loved / starred » flags between Navidrome and
 * Last.fm. Policy: **loved wins** — we only ever add love status, never
 * remove it. Running both directions = union of the two states.
 *
 * Tracks-only (Last.fm has no notion of starred album or artist).
 */
class LovesSyncService
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly LastFmClient $client,
    ) {
    }

    /**
     * Last.fm → Navidrome : pull the user's full loved-tracks list and
     * upsert annotation.starred=1 for each match. Requires a writable
     * Navidrome DB (run with --auto-stop or while Navidrome is stopped).
     *
     * @param callable(int, int, int): void|null $progress  fn(considered, applied, unmatched)
     */
    public function pullLastFmToNavidrome(
        string $apiKey,
        string $lastFmUser,
        bool $dryRun = false,
        ?callable $progress = null,
    ): LovesSyncReport {
        $report = new LovesSyncReport();

        $writeOpen = false;
        try {
            foreach ($this->client->iterateLovedTracks($apiKey, $lastFmUser) as $loved) {
                $report->considered++;

                $mediaFileId = $this->resolveMediaFileId($loved->artist, $loved->title, $loved->mbid);
                if ($mediaFileId === null) {
                    $report->unmatched++;
                    if ($progress !== null && $report->considered % 25 === 0) {
                        $progress($report->considered, $report->applied, $report->unmatched);
                    }
                    continue;
                }

                if ($dryRun) {
                    $report->applied++;
                    if ($progress !== null && $report->considered % 25 === 0) {
                        $progress($report->considered, $report->applied, $report->unmatched);
                    }
                    continue;
                }

                if (!$writeOpen) {
                    $this->navidrome->beginWriteTransaction();
                    $writeOpen = true;
                }

                try {
                    $changed = $this->navidrome->markStarred($mediaFileId, $loved->lovedAt);
                    if ($changed) {
                        $report->applied++;
                    } else {
                        $report->alreadyInSync++;
                    }
                } catch (\Throwable) {
                    $report->errors++;
                }

                if ($progress !== null && $report->considered % 25 === 0) {
                    $progress($report->considered, $report->applied, $report->unmatched);
                }
            }

            if ($writeOpen) {
                $this->navidrome->commitWrite();
                $writeOpen = false;
            }
        } catch (\Throwable $e) {
            if ($writeOpen) {
                $this->navidrome->rollbackWrite();
            }
            throw $e;
        } finally {
            if (!$dryRun) {
                $this->navidrome->walCheckpointTruncate();
                $this->navidrome->closeWriteConnection();
            }
        }

        if ($progress !== null) {
            $progress($report->considered, $report->applied, $report->unmatched);
        }

        return $report;
    }

    /**
     * Navidrome → Last.fm : iterate the user's starred media_files and
     * call `track.love` for each (artist, title) not already loved on
     * Last.fm. Reads the existing loved list once up front to skip
     * redundant API calls — track.love is idempotent server-side but
     * Last.fm's per-key rate limit makes restraint cheap.
     *
     * @param callable(int, int, int): void|null $progress  fn(considered, applied, unmatched)
     */
    public function pushNavidromeToLastFm(
        string $apiKey,
        string $apiSecret,
        string $sessionKey,
        string $lastFmUser,
        bool $dryRun = false,
        ?callable $progress = null,
    ): LovesSyncReport {
        $report = new LovesSyncReport();

        $alreadyLoved = [];
        foreach ($this->client->iterateLovedTracks($apiKey, $lastFmUser) as $loved) {
            $alreadyLoved[self::normKey($loved->artist, $loved->title)] = true;
        }

        foreach ($this->navidrome->iterateStarredMediaFiles() as $row) {
            $report->considered++;
            $artist = trim($row['artist']);
            $title = trim($row['title']);
            if ($artist === '' || $title === '') {
                $report->unmatched++;
                continue;
            }

            $key = self::normKey($artist, $title);
            if (isset($alreadyLoved[$key])) {
                $report->alreadyInSync++;
                if ($progress !== null && $report->considered % 25 === 0) {
                    $progress($report->considered, $report->applied, $report->unmatched);
                }
                continue;
            }

            if ($dryRun) {
                $report->applied++;
                $alreadyLoved[$key] = true;
                if ($progress !== null && $report->considered % 25 === 0) {
                    $progress($report->considered, $report->applied, $report->unmatched);
                }
                continue;
            }

            try {
                $this->client->trackLove($apiKey, $apiSecret, $sessionKey, $artist, $title);
                $report->applied++;
                $alreadyLoved[$key] = true;
            } catch (\Throwable) {
                $report->errors++;
            }

            if ($progress !== null && $report->considered % 25 === 0) {
                $progress($report->considered, $report->applied, $report->unmatched);
            }
        }

        if ($progress !== null) {
            $progress($report->considered, $report->applied, $report->unmatched);
        }

        return $report;
    }

    /**
     * Resolve a Last.fm loved track to a Navidrome media_file id. MBID
     * lookup wins when available (reliable when both sides have
     * MusicBrainz tags); otherwise we fall back to the full (artist,
     * title) cascade NavidromeRepository already uses for scrobble
     * matching — same logic as the scrobble sync.
     */
    private function resolveMediaFileId(string $artist, string $title, ?string $mbid): ?string
    {
        if ($mbid !== null && $mbid !== '') {
            $id = $this->navidrome->findMediaFileByMbid($mbid);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->navidrome->findMediaFileByArtistTitle($artist, $title);
    }

    private static function normKey(string $artist, string $title): string
    {
        return mb_strtolower(trim($artist)) . "\0" . mb_strtolower(trim($title));
    }
}
