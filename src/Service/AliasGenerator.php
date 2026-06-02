<?php

namespace App\Service;

use App\Entity\LastFmAlias;
use App\Entity\LastFmArtistAlias;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auto-generates high-confidence Last.fm → Navidrome aliases for scrobbles the
 * matching cascade left unmatched, using MusicBrainz ids as ground truth and
 * a bounded Levenshtein fallback.
 *
 * Strategies (each alias is created by the first one that fires):
 *
 *  - **artist-mbid** (artist alias): the scrobble's `mbid_artist` is owned by
 *    the library (via the `artist` table) under a *different* name. One alias
 *    rewrites every track of that artist. e.g. `MPL → Ma Pauvre Lucette`.
 *
 *  - **album-mbid-exact** (track alias): the scrobble's `mbid_album` is an
 *    album we own (`media_file.mbz_album_id`) and exactly one track in it
 *    shares the normalized title. The album pins the release, the title pins
 *    the track — near-zero false-positive rate.
 *
 *  - **album-mbid-fuzzy** (track alias): same album anchor, but the title is
 *    matched by Levenshtein (catches version markers / spelling). The
 *    candidate set is just the album's tracks, so it stays safe.
 *
 *  - **artist-fuzzy** (track alias): the artist is owned by name and exactly
 *    one of its tracks matches the title within a tight Levenshtein distance.
 *    Broadest reach, highest risk — kept on a short leash via the distance and
 *    a strict "unique best, no tie" rule.
 *
 * Mirrors the UI side-effects on creation (see {@see \App\Controller\LastFmAliasController}
 * / {@see \App\Controller\LastFmArtistAliasController}): the match-cache row(s)
 * for the affected couple / artist are purged so a subsequent rematch re-runs
 * through the new alias instead of a stale negative entry. Reads Navidrome
 * read-only; writes only to the tools DB (alias + cache tables).
 */
class AliasGenerator
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly LastFmAliasRepository $aliasRepo,
        private readonly LastFmArtistAliasRepository $artistAliasRepo,
        private readonly LastFmMatchCacheRepository $cacheRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param callable(AliasGenerationReport): void|null $progress
     */
    public function generate(AliasGenerationOptions $opts, ?callable $progress = null): AliasGenerationReport
    {
        $report = new AliasGenerationReport();
        $report->dryRun = $opts->dryRun;

        $owned = $this->navidrome->getKnownArtistsNormalized();

        if ($opts->artistMbid) {
            $this->generateArtistAliases($opts, $report, $owned);
        }

        if ($opts->albumExact || $opts->albumFuzzy || $opts->artistFuzzy) {
            $this->generateTrackAliases($opts, $report, $progress);
        }

        return $report;
    }

    /**
     * @param array<string, true> $owned normalized artist names present in the library
     */
    private function generateArtistAliases(AliasGenerationOptions $opts, AliasGenerationReport $report, array $owned): void
    {
        $namesByMbid = $this->navidrome->getArtistNamesByMbid();
        if ($namesByMbid === []) {
            return;
        }

        // Preload existing artist aliases once (O(1) checks, no per-row SELECT).
        $existing = $this->artistAliasRepo->existingSourceNorms();
        $seen = [];
        foreach ($this->syncRepo->unmatchedArtistMbids($opts->target) as $row) {
            $source = (string) $row['artist'];
            $mbid = (string) $row['mbid_artist'];
            $plays = (int) $row['plays'];

            $sourceNorm = NavidromeRepository::normalize($source);
            if ($sourceNorm === '' || isset($seen[$sourceNorm])) {
                continue;
            }
            // Library doesn't own this MBID, or the source name is already an
            // owned artist (so the name isn't what's blocking the match).
            if (!isset($namesByMbid[$mbid]) || isset($owned[$sourceNorm])) {
                continue;
            }

            $target = null;
            foreach ($namesByMbid[$mbid] as $candidate) {
                $candidateNorm = NavidromeRepository::normalize($candidate);
                if ($candidateNorm !== '' && $candidateNorm !== $sourceNorm && isset($owned[$candidateNorm])) {
                    $target = $candidate;
                    break;
                }
            }
            if ($target === null) {
                continue;
            }

            $seen[$sourceNorm] = true;

            if (isset($existing[$sourceNorm])) {
                $report->artistAliasesSkipped++;
                continue;
            }
            $existing[$sourceNorm] = true;

            $report->artistAliasesCreated++;
            $report->playsCoveredArtist += $plays;
            $this->addSample($report, 'artist', $source, $target, 'artist-mbid', $plays);

            if (!$opts->dryRun) {
                $this->em->persist(new LastFmArtistAlias($source, $target));
                $this->em->flush();
                $this->cacheRepo->purgeByArtist($source);
            }
        }
    }

    /**
     * @param callable(AliasGenerationReport): void|null $progress
     */
    private function generateTrackAliases(AliasGenerationOptions $opts, AliasGenerationReport $report, ?callable $progress): void
    {
        $byAlbum = ($opts->albumExact || $opts->albumFuzzy)
            ? $this->navidrome->getMediaFilesByAlbumMbid()
            : [];
        // Always loaded: drives both the "already resolvable" gate (below) and
        // the artist-fuzzy strategy.
        $byArtist = $this->navidrome->getMediaFilesByArtistNorm();

        // Preload existing track aliases once (O(1) checks, no per-couple SELECT).
        $existing = $this->aliasRepo->existingNormalizedKeys();

        $processed = 0;
        foreach ($this->syncRepo->unmatchedCouples($opts->target) as $row) {
            if ($opts->limit > 0 && $processed >= $opts->limit) {
                break;
            }
            $processed++;
            $report->couplesConsidered++;

            $artist = (string) $row['artist'];
            $title = (string) $row['title'];
            $plays = (int) $row['plays'];
            $titleNorm = NavidromeRepository::normalize($title);
            $artistNorm = NavidromeRepository::normalize($artist);
            if ($titleNorm === '') {
                continue;
            }

            $existKey = $artistNorm . "\x1f" . $titleNorm;
            if (isset($existing[$existKey])) {
                $report->trackExistingSkipped++;
                continue;
            }

            // Skip couples a plain rematch would already resolve: an exact
            // normalized (artist, title) is present in the library, so the
            // unmatched status is merely stale (the track was added after the
            // last match run). Hard-coding an alias for these adds no value.
            if (isset($byArtist[$artistNorm]) && $this->bucketHasTitle($byArtist[$artistNorm], $titleNorm)) {
                $report->cascadeResolvable++;
                continue;
            }

            $albumMbids = array_filter(explode(',', (string) ($row['mbid_albums'] ?? '')), static fn (string $s) => $s !== '');

            $mfid = null;
            $strategy = null;
            $ambiguous = false;

            if ($opts->albumExact) {
                foreach ($albumMbids as $mb) {
                    if (!isset($byAlbum[$mb])) {
                        continue;
                    }
                    [$hit, $amb] = $this->exactInBucket($byAlbum[$mb], $titleNorm);
                    if ($hit !== null) {
                        $mfid = $hit;
                        $strategy = 'album-mbid-exact';
                        break;
                    }
                    $ambiguous = $ambiguous || $amb;
                }
            }

            if ($mfid === null && $opts->albumFuzzy) {
                foreach ($albumMbids as $mb) {
                    if (!isset($byAlbum[$mb])) {
                        continue;
                    }
                    [$hit, $amb] = $this->fuzzyInBucket($byAlbum[$mb], $titleNorm, $opts->albumFuzzyMaxDistance);
                    if ($hit !== null) {
                        $mfid = $hit;
                        $strategy = 'album-mbid-fuzzy';
                        break;
                    }
                    $ambiguous = $ambiguous || $amb;
                }
            }

            if ($mfid === null && $opts->artistFuzzy) {
                if ($artistNorm !== '' && isset($byArtist[$artistNorm])) {
                    [$hit, $amb] = $this->fuzzyInBucket($byArtist[$artistNorm], $titleNorm, $opts->artistFuzzyMaxDistance);
                    if ($hit !== null) {
                        $mfid = $hit;
                        $strategy = 'artist-fuzzy';
                    } else {
                        $ambiguous = $ambiguous || $amb;
                    }
                }
            }

            if ($mfid === null) {
                if ($ambiguous) {
                    $report->trackAmbiguous++;
                }
                continue;
            }

            match ($strategy) {
                'album-mbid-exact' => $report->trackAlbumExact++,
                'album-mbid-fuzzy' => $report->trackAlbumFuzzy++,
                'artist-fuzzy' => $report->trackArtistFuzzy++,
                default => null,
            };
            $report->playsCoveredTrack += $plays;
            $existing[$existKey] = true;
            $this->addSample($report, 'track', $artist . ' — ' . $title, $mfid, (string) $strategy, $plays);

            if (!$opts->dryRun) {
                $this->em->persist(new LastFmAlias($artist, $title, $mfid));
                $this->em->flush();
                $this->cacheRepo->purgeByCouple($artist, $title);
            }

            if ($progress !== null && $report->couplesConsidered % 1000 === 0) {
                $progress($report);
            }
        }

        if ($progress !== null) {
            $progress($report);
        }
    }

    /**
     * True when any candidate in the bucket carries the given normalized
     * title — i.e. the plain (artist, title) cascade would resolve it.
     *
     * @param list<array{id: string, title_norm: string}> $bucket
     */
    private function bucketHasTitle(array $bucket, string $titleNorm): bool
    {
        foreach ($bucket as $mf) {
            if ($mf['title_norm'] === $titleNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact normalized-title match within a candidate bucket.
     *
     * @param list<array{id: string, title_norm: string}> $bucket
     *
     * @return array{0: ?string, 1: bool} [media_file id | null, ambiguous?]
     */
    private function exactInBucket(array $bucket, string $titleNorm): array
    {
        $hits = [];
        foreach ($bucket as $mf) {
            if ($mf['title_norm'] === $titleNorm) {
                $hits[$mf['id']] = true;
            }
        }
        if (count($hits) === 1) {
            return [(string) array_key_first($hits), false];
        }

        return [null, count($hits) > 1];
    }

    /**
     * Best unique Levenshtein title match within a candidate bucket. Returns
     * the id only when there is a single strict winner within `$maxDistance`
     * (a tie at the best score is treated as ambiguous → no alias).
     *
     * @param list<array{id: string, title_norm: string}> $bucket
     *
     * @return array{0: ?string, 1: bool} [media_file id | null, ambiguous?]
     */
    private function fuzzyInBucket(array $bucket, string $titleNorm, int $maxDistance): array
    {
        // levenshtein() is byte-capped at 255; bail rather than crash.
        if ($maxDistance <= 0 || strlen($titleNorm) > 255) {
            return [null, false];
        }

        $bestId = null;
        $bestScore = PHP_INT_MAX;
        $tie = false;
        foreach ($bucket as $mf) {
            $candidate = $mf['title_norm'];
            if ($candidate === '' || strlen($candidate) > 255) {
                continue;
            }
            $score = levenshtein($titleNorm, $candidate);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestId = $mf['id'];
                $tie = false;
            } elseif ($score === $bestScore && $mf['id'] !== $bestId) {
                $tie = true;
            }
        }

        if ($bestId !== null && $bestScore <= $maxDistance && !$tie) {
            return [$bestId, false];
        }

        // Had a within-range candidate but it was tied → ambiguous.
        return [null, $bestId !== null && $bestScore <= $maxDistance && $tie];
    }

    private function addSample(AliasGenerationReport $report, string $type, string $source, string $target, string $strategy, int $plays): void
    {
        if (count($report->samples) >= 40) {
            return;
        }
        $report->samples[] = [
            'type' => $type,
            'source' => $source,
            'target' => $target,
            'strategy' => $strategy,
            'plays' => $plays,
        ];
    }
}
