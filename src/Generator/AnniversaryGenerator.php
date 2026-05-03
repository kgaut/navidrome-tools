<?php

namespace App\Generator;

use App\Navidrome\NavidromeRepository;

/**
 * « Souvenirs » à la Spotify : top morceaux écoutés autour du jour J,
 * mais N années en arrière (1, 2, 5, 10 ans…).
 *
 * Plusieurs offsets possibles dans une seule playlist : si un morceau
 * était au top à la même date il y a 2 ans ET il y a 5 ans, il remonte
 * en tête (somme des plays sur les fenêtres).
 */
class AnniversaryGenerator implements PlaylistGeneratorInterface
{
    public function __construct(private readonly NavidromeRepository $navidrome)
    {
    }

    public function getKey(): string
    {
        return 'anniversary';
    }

    public function getLabel(): string
    {
        return 'Anniversaire (jour J il y a N années)';
    }

    public function getDescription(): string
    {
        return 'Top morceaux écoutés à la même date il y a 1 / 2 / 5 / 10 ans (configurable).';
    }

    public function getParameterSchema(): array
    {
        return [
            new ParameterDefinition(
                name: 'years_offsets',
                label: 'Années en arrière (séparées par virgule)',
                type: ParameterDefinition::TYPE_STRING,
                default: '1,2,5,10',
                help: 'Liste d\'offsets en années, ex. « 1,2,5,10 ». Le générateur agrège tous ces souvenirs.',
            ),
            new ParameterDefinition(
                name: 'window_days',
                label: 'Largeur de la fenêtre (± jours)',
                type: ParameterDefinition::TYPE_INT,
                default: 3,
                min: 0,
                max: 30,
                help: '0 = strictement le même jour calendaire ; 7 = la semaine autour.',
            ),
        ];
    }

    public function generate(array $parameters, int $limit): array
    {
        $windows = $this->buildWindows($parameters);
        if ($windows === []) {
            return [];
        }

        return $this->navidrome->topTracksInWindows($windows, $limit);
    }

    /**
     * Bounding box of every offset window — used by the preview to scope
     * the « Plays » column. The displayed counts will be a slight
     * over-estimate (any play that happens to fall outside an offset
     * window but inside the bounding box gets counted) but the trade-off
     * is acceptable : for typical offsets the gaps between windows are
     * mostly empty (we're talking about ±3 days each, separated by
     * years).
     */
    public function getActiveWindow(array $parameters): ?array
    {
        $windows = $this->buildWindows($parameters);
        if ($windows === []) {
            return null;
        }

        $earliest = $windows[0]['from'];
        $latest = $windows[0]['to'];
        foreach ($windows as $w) {
            if ($w['from'] < $earliest) {
                $earliest = $w['from'];
            }
            if ($w['to'] > $latest) {
                $latest = $w['to'];
            }
        }

        return ['from' => $earliest, 'to' => $latest];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array{from: \DateTimeImmutable, to: \DateTimeImmutable}>
     */
    private function buildWindows(array $parameters): array
    {
        $offsets = self::parseOffsets($parameters['years_offsets'] ?? '1,2,5,10');
        $windowDays = max(0, min(30, (int) ($parameters['window_days'] ?? 3)));

        $today = new \DateTimeImmutable('today');
        $windows = [];
        foreach ($offsets as $years) {
            $center = $today->modify(sprintf('-%d years', $years));
            $from = $center->modify(sprintf('-%d days', $windowDays));
            $to = $center->modify(sprintf('+%d days', $windowDays + 1));
            $windows[] = ['from' => $from, 'to' => $to];
        }

        return $windows;
    }

    /**
     * @return list<int> deduped & sorted positive integer offsets
     */
    public static function parseOffsets(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $clean = [];
        foreach ($items as $item) {
            $n = (int) trim((string) $item);
            if ($n > 0 && $n <= 100) {
                $clean[$n] = true;
            }
        }
        $sorted = array_keys($clean);
        sort($sorted);

        return $sorted;
    }
}
