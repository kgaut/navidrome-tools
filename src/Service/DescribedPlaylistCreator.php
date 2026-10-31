<?php

namespace App\Service;

use App\Entity\DescribedPlaylist;
use App\Exception\UserFacingException;
use App\Message\GeneratePlaylistsMessage;
use App\Playlist\Definition\DescribedPlaylistDefinition;
use App\Playlist\PlaylistGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves a playlist described in free text (issue #258) and queues its first
 * generation. Shared by the web form and the MCP server (issue #276) so both
 * apply the same rules.
 */
class DescribedPlaylistCreator
{
    public const MAX_NAME_LENGTH = 100;
    public const MAX_QUERY_LENGTH = 300;
    public const MAX_TRACKS = 200;

    public function __construct(
        private readonly PlaylistGenerator $generator,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws UserFacingException when a field is invalid or the name is taken
     */
    public function create(string $name, string $query, int $trackCount, ?int $maxPlays): DescribedPlaylist
    {
        $name = trim($name);
        $query = trim($query);

        $taken = array_map(
            static fn (array $d): string => mb_strtolower($d['name']),
            $this->generator->listDefinitions(),
        );
        $error = match (true) {
            $name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH
                => sprintf('Le nom est obligatoire (%d caractères au plus).', self::MAX_NAME_LENGTH),
            in_array(mb_strtolower($name), $taken, true) => sprintf('Une playlist générée s\'appelle déjà « %s ».', $name),
            $query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH
                => sprintf('La description est obligatoire (%d caractères au plus).', self::MAX_QUERY_LENGTH),
            $trackCount < 1 || $trackCount > self::MAX_TRACKS
                => sprintf('Le nombre de morceaux doit être compris entre 1 et %d.', self::MAX_TRACKS),
            $maxPlays !== null && $maxPlays < 0 => 'Le plafond d\'écoutes ne peut pas être négatif.',
            default => null,
        };
        if ($error !== null) {
            throw new UserFacingException($error);
        }

        $playlist = new DescribedPlaylist($name, $query, $trackCount, $maxPlays);
        $this->em->persist($playlist);
        $this->em->flush();

        $this->bus->dispatch(new GeneratePlaylistsMessage(self::slugFor($playlist)));

        return $playlist;
    }

    public static function slugFor(DescribedPlaylist $playlist): string
    {
        return DescribedPlaylistDefinition::SLUG_PREFIX . $playlist->getId();
    }
}
