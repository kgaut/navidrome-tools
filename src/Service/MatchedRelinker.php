<?php

namespace App\Service;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\LastFm\LastFmApiException;
use App\LastFm\LastFmRequestException;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeDbBackup;
use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves already-written scrobbles to the track the CURRENT matching cascade
 * picks (issue #285) — e.g. IAM plays written on « … - Instrumentales »
 * before #284.
 *
 * Only rows WE wrote (`scrobble_sync.status = matched`) are candidates, minus
 * manual aliases and MBID matches; native Navidrome plays (`duplicate`) are
 * never touched. A row moves only when the new target is non-null and
 * different; one that would become unmatched stays and is counted, and so
 * does one whose recorded target is no longer in the library (stale id).
 *
 * Writing a move: delete our `scrobbles` row (user, old track, exact second)
 * — not found: nothing is written for that play, never an insert alone,
 * which would double it (#290) — insert on the new track unless a play already sits there within the
 * tolerance (then the row becomes `duplicate`), recompute both tracks'
 * annotation from `scrobbles`, update `scrobble_sync`. Batches of 100 in one
 * Navidrome transaction, backup first. The caller stops Navidrome.
 */
class MatchedRelinker
{
    public const MAX_SCROBBLES = 2000;
    private const BATCH_SIZE = 100;
    private const PAGE = 1000;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly ScrobbleMatcher $matcher,
        private readonly NavidromeRepository $navidrome,
        private readonly NavidromeDbBackup $backup,
        private readonly EntityManagerInterface $em,
        private readonly int $toleranceSeconds = 60,
    ) {
    }

    /**
     * Read-only plan. `$fromAlbum` filters on the album of the CURRENT target
     * (substring, case-insensitive), the other filters on the scrobble.
     *
     * @return array{
     *     considered: int,
     *     truncated: bool,
     *     moves: list<array{sync_id: int, from: string, to: string, strategy: string, played_at: \DateTimeImmutable}>,
     *     groups: list<array{artist: string, title: string, album: string, from_id: string, from_album: string, to_id: string, to_album: string, strategy: string, count: int}>,
     *     would_unmatch: int,
     *     stale_target: int,
     *     api_errors: int
     * }
     */
    public function plan(?string $artist, ?string $title, ?string $album, ?string $fromAlbum, int $limit): array
    {
        [$rows, $truncated] = $this->candidates($artist, $title, $album, $fromAlbum, $limit);
        $targetIds = array_values(array_unique(array_map(static fn (ScrobbleSync $s) => (string) $s->getTargetId(), $rows)));
        $albums = $this->albumsOf($targetIds);

        $out = [
            'considered' => count($rows), 'truncated' => $truncated, 'moves' => [], 'groups' => [],
            'would_unmatch' => 0, 'stale_target' => 0, 'api_errors' => 0,
        ];
        /** @var array<string, ?MatchResult> $resolved */
        $resolved = [];
        $groups = [];
        $newIds = [];
        foreach ($rows as $sync) {
            // Recorded target no longer in the library (track ids changed
            // since the write): our Navidrome row cannot be found by it, so
            // moving would only ADD a play. Left alone, counted (#290).
            if (!isset($albums[(string) $sync->getTargetId()])) {
                $out['stale_target']++;
                continue;
            }
            $scrobble = $sync->getScrobble();
            $key = implode("\0", [$scrobble->getArtist(), $scrobble->getTitle(), (string) $scrobble->getAlbum(), (string) $scrobble->getMbidTrack()]);
            if (!array_key_exists($key, $resolved)) {
                try {
                    $resolved[$key] = $this->matcher->match(new LastFmScrobble(
                        artist: $scrobble->getArtist(),
                        title: $scrobble->getTitle(),
                        album: (string) $scrobble->getAlbum(),
                        mbid: $scrobble->getMbidTrack(),
                        playedAt: $scrobble->getPlayedAt(),
                    ));
                } catch (LastFmRequestException | LastFmApiException) {
                    $resolved[$key] = null;
                    $out['api_errors']++;
                }
            }
            $result = $resolved[$key];
            if ($result === null) {
                continue;
            }
            if ($result->status !== MatchResult::STATUS_MATCHED || $result->mediaFileId === null) {
                $out['would_unmatch']++;
                continue;
            }
            $from = (string) $sync->getTargetId();
            $to = $result->mediaFileId;
            if ($to === $from) {
                continue;
            }

            $strategy = (string) $result->strategy;
            $out['moves'][] = [
                'sync_id' => (int) $sync->getId(),
                'from' => $from,
                'to' => $to,
                'strategy' => $strategy,
                'played_at' => $scrobble->getPlayedAt(),
            ];
            $newIds[$to] = true;
            $gKey = $key . "\0" . $from . "\0" . $to;
            $groups[$gKey] ??= [
                'artist' => $scrobble->getArtist(),
                'title' => $scrobble->getTitle(),
                'album' => (string) $scrobble->getAlbum(),
                'from_id' => $from,
                'from_album' => $albums[$from] ?? '',
                'to_id' => $to,
                'to_album' => '',
                'strategy' => $strategy,
                'count' => 0,
            ];
            $groups[$gKey]['count']++;
        }

        $newAlbums = $this->albumsOf(array_map('strval', array_keys($newIds)));
        foreach ($groups as &$group) {
            $group['to_album'] = $newAlbums[$group['to_id']] ?? '';
        }
        unset($group);
        usort($groups, static fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $out['groups'] = $groups;

        return $out;
    }

    /**
     * Writes a plan. Navidrome must be stopped by the caller.
     *
     * @param list<array{sync_id: int, from: string, to: string, strategy: string, played_at: \DateTimeImmutable}> $moves
     *
     * @return array{moved: int, duplicates: int, missing_old: int, backup: ?string}
     */
    public function apply(array $moves, ?RunHistory $run = null): array
    {
        $out = ['moved' => 0, 'duplicates' => 0, 'missing_old' => 0, 'backup' => null];
        if ($moves === []) {
            return $out;
        }

        $userId = $this->navidrome->resolveUserId();
        $out['backup'] = $this->backup->backup();

        try {
            foreach (array_chunk($moves, self::BATCH_SIZE) as $batch) {
                $statuses = $this->writeBatch($batch, $userId, $out);
                foreach ($batch as $i => $move) {
                    $sync = $statuses[$i] === null ? null : $this->em->find(ScrobbleSync::class, $move['sync_id']);
                    if ($sync === null) {
                        continue;
                    }
                    if ($statuses[$i] === ScrobbleSync::STATUS_DUPLICATE) {
                        $sync->markDuplicate($move['to'], $move['strategy'], $run);
                    } else {
                        $sync->markMatched($move['to'], $move['strategy'], $run);
                    }
                }
                // No clear(): the run entity belongs to RunHistoryRecorder, and
                // a relink is capped (MAX_SCROBBLES), so the identity map stays small.
                $this->em->flush();
            }
        } finally {
            $this->navidrome->walCheckpointTruncate();
            $this->navidrome->closeWriteConnection();
        }

        return $out;
    }

    /**
     * @param list<array{sync_id: int, from: string, to: string, strategy: string, played_at: \DateTimeImmutable}> $batch
     * @param array{moved: int, duplicates: int, missing_old: int, backup: ?string} $out
     *
     * @return list<?string> status per move, null = left untouched (our row not found)
     */
    private function writeBatch(array $batch, string $userId, array &$out): array
    {
        $statuses = [];
        $touched = [];
        $this->navidrome->beginWriteTransaction();
        try {
            foreach ($batch as $move) {
                // Our row not found: inserting the new one would double the
                // play (#290). Leave both sides untouched.
                if ($this->navidrome->deleteOneScrobble($userId, $move['from'], $move['played_at']) === 0) {
                    $out['missing_old']++;
                    $statuses[] = null;
                    continue;
                }
                $touched[$move['from']] = true;
                $touched[$move['to']] = true;

                if ($this->navidrome->scrobbleExistsNear($userId, $move['to'], $move['played_at'], $this->toleranceSeconds)) {
                    $statuses[] = ScrobbleSync::STATUS_DUPLICATE;
                    $out['duplicates']++;
                    continue;
                }
                $this->navidrome->insertScrobble($userId, $move['to'], $move['played_at']);
                $statuses[] = ScrobbleSync::STATUS_MATCHED;
                $out['moved']++;
            }
            $this->navidrome->reconcileAnnotationForMediaFiles($userId, array_map('strval', array_keys($touched)));
            $this->navidrome->commitWrite();
        } catch (\Throwable $e) {
            try {
                $this->navidrome->rollbackWrite();
            } catch (\Throwable) {
            }
            throw $e;
        }

        return $statuses;
    }

    /**
     * Up to `$limit` candidates that pass every filter, `$fromAlbum` included
     * (issue #294): the album of the current target lives in navidrome.db,
     * so it cannot join the data.db query — candidates are read by pages and
     * filtered page by page, instead of filtering a first window of `$limit`
     * rows and silently missing the rest. `truncated` = at least one more
     * candidate passes the filters beyond the limit.
     *
     * @return array{0: list<ScrobbleSync>, 1: bool}
     */
    private function candidates(?string $artist, ?string $title, ?string $album, ?string $fromAlbum, int $limit): array
    {
        $filtered = $fromAlbum !== null && $fromAlbum !== '';
        $kept = [];
        $afterId = 0;
        while (true) {
            $page = $this->syncRepo->matchedForRelink(
                ScrobbleSync::TARGET_NAVIDROME,
                $artist,
                $title,
                $album,
                $filtered ? self::PAGE : $limit + 1 - count($kept),
                $afterId,
            );
            if ($page === []) {
                return [$kept, false];
            }
            $afterId = (int) $page[count($page) - 1]->getId();

            $albums = $filtered ? $this->albumsOf(array_values(array_unique(array_map(static fn (ScrobbleSync $s) => (string) $s->getTargetId(), $page)))) : [];
            foreach ($page as $sync) {
                if ($filtered && mb_stripos($albums[(string) $sync->getTargetId()] ?? '', (string) $fromAlbum) === false) {
                    continue;
                }
                if (count($kept) >= $limit) {
                    return [$kept, true];
                }
                $kept[] = $sync;
            }
            if (!$filtered) {
                return [$kept, false];
            }
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> media_file id → album
     */
    private function albumsOf(array $ids): array
    {
        $albums = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->navidrome->getMediaFileMetadata($chunk) as $meta) {
                $albums[$meta['id']] = $meta['album'];
            }
        }

        return $albums;
    }
}
