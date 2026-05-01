<?php

namespace App\Tests\Service;

use App\Entity\PlaylistDefinition;
use App\Generator\PlaylistGeneratorInterface;
use App\Service\PlaylistNameRenderer;
use PHPUnit\Framework\TestCase;

class PlaylistNameRendererTest extends TestCase
{
    public function testSubstitutionsCoverDateLabelAndParams(): void
    {
        $renderer = new PlaylistNameRenderer();
        $now = new \DateTimeImmutable('2026-05-01 10:00:00');

        $generator = new class implements PlaylistGeneratorInterface {
            public function getKey(): string
            {
                return 'top-last-days';
            }
            public function getLabel(): string
            {
                return 'Top des X derniers jours';
            }
            public function getDescription(): string
            {
                return '';
            }
            public function getParameterSchema(): array
            {
                return [];
            }
            public function generate(array $parameters, int $limit): array
            {
                return [];
            }
        };

        $def = (new PlaylistDefinition())
            ->setName('myDef')
            ->setGeneratorKey('top-last-days')
            ->setParameters(['days' => 30]);

        $template = '{label} ({param:days}j) — {date} / {month} / {year}';
        $this->assertSame(
            'Top des X derniers jours (30j) — 2026-05-01 / 2026-05 / 2026',
            $renderer->render($template, $generator, $def, $now),
        );
    }
}
