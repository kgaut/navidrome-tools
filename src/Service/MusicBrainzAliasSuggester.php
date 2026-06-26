<?php

namespace App\Service;

use App\Entity\LastFmArtistAlias;
use App\MusicBrainz\MusicBrainzArtistCandidate;
use App\MusicBrainz\MusicBrainzClient;
use App\MusicBrainz\MusicBrainzException;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Online complement to {@see AliasGenerator}: pulls candidate artist names +
 * aliases from MusicBrainz to bridge unmatched scrobbles whose artist string
 * doesn't exist as-is in the library but whose MB record carries an alias
 * that does (e.g. `Beatles, The` → `The Beatles`, `Sigur Rós` ↔ `Sigur Ros`).
 *
 *  - Builds two index sets once: every alias source already in
 *    `lastfm_artist_alias` (skip), every normalized artist name owned by the
 *    library (match target).
 *  - Walks distinct unmatched artists in the chosen target's scrobble_sync,
 *    ordered by play volume so the biggest wins land first if the run is
 *    capped via --limit.
 *  - For each, queries MB once (the caller must throttle — MB rate-limits at
 *    ~1 req/s per UA) and intersects MB's candidate names + aliases with the
 *    library set:
 *      • exactly one library artist matched → UNIQUE → auto-apply when not
 *        in dry-run.
 *      • multiple library artists matched → AMBIGUOUS → optional confirm
 *        callback (interactive prompt) decides; falls back to skip.
 *      • zero library matches → NO_MATCH, reported.
 *  - On apply, the alias is persisted then the match-cache rows for the
 *    source artist are purged so a subsequent `app:scrobbles:rematch` can
 *    resolve the unmatched scrobbles through the new alias.
 *
 * Reads Navidrome read-only; writes only the tools DB.
 */
class MusicBrainzAliasSuggester
{
    /** Minimum MB score (0-100) we'll trust as a real candidate. MB returns
     *  fuzzy matches all the way down to 50 for poor name overlaps. */
    private const MIN_MB_SCORE = 80;

    public function __construct(
        private readonly MusicBrainzClient $client,
        private readonly NavidromeRepository $navidrome,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly LastFmArtistAliasRepository $artistAliasRepo,
        private readonly LastFmMatchCacheRepository $cacheRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param ?callable(string $sourceArtist): void                                   $beforeQuery
     *                                                  Called right before each MB call
     *                                                  — host for the throttle sleep.
     * @param ?callable(MusicBrainzAliasSuggestion): ?string                          $confirm
     *                                                  Invoked on ambiguous suggestions.
     *                                                  Return the chosen target (one of
     *                                                  `targetCandidates`) to apply, or
     *                                                  null to skip. When null, ambiguous
     *                                                  suggestions are always skipped.
     */
    public function suggest(
        string $target,
        bool $dryRun,
        int $limit,
        ?callable $beforeQuery = null,
        ?callable $confirm = null,
        int $minPlays = 0,
    ): MusicBrainzAliasReport {
        $report = new MusicBrainzAliasReport();
        $report->dryRun = $dryRun;

        $existing = $this->artistAliasRepo->existingSourceNorms();
        $owned = $this->navidrome->getKnownArtistsNormalized();
        if ($owned === []) {
            return $report;
        }
        // Reverse index: normalized lib name → canonical lib name (the first
        // spelling we encounter via the existing helper).
        $ownedCanonical = $this->canonicalLibNames($owned);

        $processed = 0;
        foreach ($this->syncRepo->unmatchedArtistsWithPlays($target) as $row) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $source = (string) $row['artist'];
            $plays = (int) $row['plays'];

            // `unmatchedArtistsWithPlays` is ordered by descending plays, so
            // once we drop below the floor every remaining artist is too —
            // stop scanning rather than spending MB calls on the long tail.
            if ($minPlays > 0 && $plays < $minPlays) {
                break;
            }

            $sourceNorm = NavidromeRepository::normalize($source);

            $report->artistsConsidered++;

            if ($sourceNorm === '') {
                continue;
            }
            if (isset($existing[$sourceNorm])) {
                $report->skippedAlreadyAliased++;
                continue;
            }
            if (isset($owned[$sourceNorm])) {
                // The library already owns this exact spelling — the artist
                // isn't what's blocking the match (track title likely is).
                $report->skippedAlreadyOwned++;
                continue;
            }

            $processed++;
            if ($beforeQuery !== null) {
                $beforeQuery($source);
            }
            $report->artistsQueried++;

            try {
                $candidates = $this->client->searchArtist($source);
            } catch (MusicBrainzException) {
                $report->mbErrors++;
                continue;
            }

            $suggestion = $this->buildSuggestion($source, $plays, $candidates, $owned, $ownedCanonical);
            $report->addSample($suggestion);

            if ($suggestion->kind === MusicBrainzAliasSuggestion::KIND_NO_MATCH) {
                $report->noMatch++;
                continue;
            }

            $chosen = null;
            if ($suggestion->kind === MusicBrainzAliasSuggestion::KIND_UNIQUE) {
                $chosen = $suggestion->uniqueTarget();
            } elseif ($confirm !== null) {
                $chosen = $confirm($suggestion);
                if ($chosen !== null && !in_array($chosen, $suggestion->targetCandidates, true)) {
                    $chosen = null;
                }
            }

            if ($chosen === null) {
                $report->ambiguous++;
                continue;
            }

            $report->aliasesCreated++;
            $report->playsCovered += $plays;
            $existing[$sourceNorm] = true;

            if (!$dryRun) {
                $this->em->persist(new LastFmArtistAlias($source, $chosen));
                $this->em->flush();
                $this->cacheRepo->purgeByArtist($source);
            }
        }

        return $report;
    }

    /**
     * @param array<string, true>                  $owned          normalized lib artists
     * @param array<string, string>                $ownedCanonical normalized → canonical
     * @param list<MusicBrainzArtistCandidate>     $candidates
     */
    private function buildSuggestion(
        string $source,
        int $plays,
        array $candidates,
        array $owned,
        array $ownedCanonical,
    ): MusicBrainzAliasSuggestion {
        $matches = [];
        $evidence = [];

        foreach ($candidates as $cand) {
            if ($cand->score < self::MIN_MB_SCORE) {
                continue;
            }
            $matchedVia = null;
            foreach ($cand->allNames() as $name) {
                $norm = NavidromeRepository::normalize($name);
                if ($norm === '' || !isset($owned[$norm])) {
                    continue;
                }
                $libName = $ownedCanonical[$norm];
                $matches[$libName] = true;
                $matchedVia ??= $name;
            }
            $evidence[] = [
                'mbid' => $cand->mbid,
                'name' => $cand->name,
                'score' => $cand->score,
                'matched_via' => $matchedVia,
            ];
        }

        $targets = array_keys($matches);
        $kind = match (true) {
            count($targets) === 1 => MusicBrainzAliasSuggestion::KIND_UNIQUE,
            count($targets) > 1 => MusicBrainzAliasSuggestion::KIND_AMBIGUOUS,
            default => MusicBrainzAliasSuggestion::KIND_NO_MATCH,
        };

        return new MusicBrainzAliasSuggestion($source, $plays, $kind, $targets, $evidence);
    }

    /**
     * Resolve normalized lib artist names back to a canonical spelling for
     * UI / alias-target use. The existing helper only exposes the
     * normalized set, so we re-query with a join-friendly statement here.
     *
     * @param array<string, true> $owned
     *
     * @return array<string, string>
     */
    private function canonicalLibNames(array $owned): array
    {
        $names = $this->navidrome->getKnownArtistOriginalNames();
        $out = [];
        foreach ($names as $name) {
            $norm = NavidromeRepository::normalize($name);
            if ($norm !== '' && isset($owned[$norm]) && !isset($out[$norm])) {
                $out[$norm] = $name;
            }
        }

        return $out;
    }
}
