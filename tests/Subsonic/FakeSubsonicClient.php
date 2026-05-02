<?php

namespace App\Tests\Subsonic;

use App\Subsonic\SubsonicClient;

/**
 * Stub SubsonicClient that bypasses parent::__construct (no HTTP) and
 * keeps the starred set in memory. Captures star/unstar calls so tests
 * can assert the expected mutations.
 */
final class FakeSubsonicClient extends SubsonicClient
{
    /**
     * @var array<string, array{id: string, title: string, artist: string, album: string}>
     */
    private array $starred = [];

    /** @var list<string> */
    public array $starCalls = [];
    /** @var list<string> */
    public array $unstarCalls = [];

    /**
     * @param list<array{id: string, title: string, artist: string, album: string}> $initialStarred
     */
    public function __construct(array $initialStarred = [])
    {
        // Skip parent::__construct on purpose — no HTTP client needed.
        foreach ($initialStarred as $s) {
            $this->starred[$s['id']] = $s;
        }
    }

    public function getStarred(): array
    {
        return array_values($this->starred);
    }

    public function starTracks(string ...$songIds): void
    {
        foreach ($songIds as $id) {
            if ($id === '') {
                continue;
            }
            $this->starCalls[] = $id;
            $this->starred[$id] = ['id' => $id, 'title' => '', 'artist' => '', 'album' => ''];
        }
    }

    public function unstarTracks(string ...$songIds): void
    {
        foreach ($songIds as $id) {
            if ($id === '') {
                continue;
            }
            $this->unstarCalls[] = $id;
            unset($this->starred[$id]);
        }
    }
}
