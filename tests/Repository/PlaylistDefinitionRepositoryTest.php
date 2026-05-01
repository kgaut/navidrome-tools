<?php

namespace App\Tests\Repository;

use App\Entity\PlaylistDefinition;
use App\Repository\PlaylistDefinitionRepository;
use PHPUnit\Framework\TestCase;

class PlaylistDefinitionRepositoryTest extends TestCase
{
    public function testBuildDuplicateNameAppendsCopySuffix(): void
    {
        $taken = ['Top 30j' => true];
        $repo = $this->makeRepoWithTaken($taken);

        $this->assertSame('Top 30j (copie)', $repo->buildDuplicateName('Top 30j'));
    }

    public function testBuildDuplicateNameIncrementsWhenSuffixAlreadyTaken(): void
    {
        $taken = [
            'Top 30j' => true,
            'Top 30j (copie)' => true,
            'Top 30j (copie 2)' => true,
        ];
        $repo = $this->makeRepoWithTaken($taken);

        $this->assertSame('Top 30j (copie 3)', $repo->buildDuplicateName('Top 30j'));
    }

    public function testBuildDuplicateNameWhenSourceNameIsFreshlyAvailable(): void
    {
        // Edge case: even when "<base> (copie)" itself is free, we use it.
        $repo = $this->makeRepoWithTaken([]);
        $this->assertSame('Untitled (copie)', $repo->buildDuplicateName('Untitled'));
    }

    /**
     * @param array<string, true> $taken
     */
    private function makeRepoWithTaken(array $taken): PlaylistDefinitionRepository
    {
        return new class ($taken) extends PlaylistDefinitionRepository {
            /** @param array<string, true> $taken */
            public function __construct(private readonly array $taken)
            {
                // Skip parent::__construct: we don't need the underlying ORM EM.
            }

            public function findOneByName(string $name): ?PlaylistDefinition
            {
                return isset($this->taken[$name]) ? new PlaylistDefinition() : null;
            }
        };
    }
}
