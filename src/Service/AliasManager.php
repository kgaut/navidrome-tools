<?php

namespace App\Service;

use App\Entity\LastFmAlias;
use App\Entity\LastFmArtistAlias;
use App\Exception\UserFacingException;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates matching aliases from the MCP server (issue #276), with the same
 * side effects as the alias forms: the match cache rows the alias makes
 * stale are purged. Unlike the forms, the target is checked against the
 * library, since nobody reviews it on a page first.
 */
class AliasManager
{
    public function __construct(
        private readonly LastFmAliasRepository $trackAliases,
        private readonly LastFmArtistAliasRepository $artistAliases,
        private readonly LastFmMatchCacheRepository $cache,
        private readonly NavidromeRepository $navidrome,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Maps a scrobbled (artist, title) couple to a media_file, or marks it
     * as ignored when `$targetMediaFileId` is null.
     *
     * @throws UserFacingException
     */
    public function createTrackAlias(string $sourceArtist, string $sourceTitle, ?string $targetMediaFileId): LastFmAlias
    {
        $sourceArtist = trim($sourceArtist);
        $sourceTitle = trim($sourceTitle);
        $target = $targetMediaFileId !== null && trim($targetMediaFileId) !== '' ? trim($targetMediaFileId) : null;

        if ($sourceArtist === '' || $sourceTitle === '') {
            throw new UserFacingException('L\'artiste et le titre scrobblés sont obligatoires.');
        }
        if ($target !== null && $this->navidrome->getMediaFileMetadata([$target]) === []) {
            throw new UserFacingException(sprintf('Aucun morceau d\'id « %s » dans la bibliothèque Navidrome.', $target));
        }
        $existing = $this->trackAliases->findByScrobble($sourceArtist, $sourceTitle);
        if ($existing !== null) {
            throw new UserFacingException(sprintf(
                'Un alias existe déjà pour « %s — %s » (vers %s) : modifiez-le depuis l\'interface.',
                $existing->getSourceArtist(),
                $existing->getSourceTitle(),
                $existing->getTargetMediaFileId() ?? 'ignoré',
            ));
        }

        $alias = new LastFmAlias($sourceArtist, $sourceTitle, $target);
        $this->persist($alias);
        $this->cache->purgeByCouple($sourceArtist, $sourceTitle);
        $this->em->flush();

        return $alias;
    }

    /**
     * Rewrites a scrobbled artist name to an artist of the library.
     *
     * @throws UserFacingException
     */
    public function createArtistAlias(string $sourceArtist, string $targetArtist): LastFmArtistAlias
    {
        $sourceArtist = trim($sourceArtist);
        $targetArtist = trim($targetArtist);

        if ($sourceArtist === '' || $targetArtist === '') {
            throw new UserFacingException('L\'artiste scrobblé et l\'artiste cible sont obligatoires.');
        }
        if (NavidromeRepository::normalize($sourceArtist) === NavidromeRepository::normalize($targetArtist)) {
            throw new UserFacingException('L\'artiste cible est identique à l\'artiste scrobblé.');
        }
        if (!$this->navidrome->hasArtistInLibrary($targetArtist)) {
            throw new UserFacingException(sprintf('L\'artiste « %s » est absent de la bibliothèque Navidrome.', $targetArtist));
        }
        $existing = $this->artistAliases->findBySourceArtist($sourceArtist);
        if ($existing !== null) {
            throw new UserFacingException(sprintf(
                'Un alias existe déjà pour « %s » (vers « %s ») : modifiez-le depuis l\'interface.',
                $existing->getSourceArtist(),
                $existing->getTargetArtist(),
            ));
        }

        $alias = new LastFmArtistAlias($sourceArtist, $targetArtist);
        $this->persist($alias);
        $this->cache->purgeByArtist($sourceArtist);
        $this->em->flush();

        return $alias;
    }

    private function persist(object $alias): void
    {
        try {
            $this->em->persist($alias);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new UserFacingException('Un alias normalisé identique existe déjà : modifiez-le depuis l\'interface.');
        }
    }
}
