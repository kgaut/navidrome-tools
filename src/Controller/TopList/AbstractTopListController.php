<?php

namespace App\Controller\TopList;

use App\Filter\DateCascadeFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cœur partagé des six pages /(lastfm|navidrome)/top-(artists|albums|tracks).
 *
 * Décide entre trois sources de données selon la présence d'un filtre date :
 *  - **filtre posé** (`year` / `month` / `day`) → requête live, le filtre
 *    réduit la volumétrie donc c'est rapide même sur les grosses bases.
 *  - **aucun filtre, snapshot disponible** → lecture instantanée du
 *    pré-calcul stocké par la commande `*:stats:compute`.
 *  - **aucun filtre, pas de snapshot** → fallback live + bandeau ambre
 *    qui invite à lancer la commande.
 *
 * Les classes filles fournissent les six briques différenciantes
 * (fetchLive / fetchSnapshot / snapshotKey / availableYears / template /
 * computeCommand) et exposent leur propre route.
 */
abstract class AbstractTopListController extends AbstractController
{
    protected const TOP_N = 100;

    /**
     * Requête live (avec ou sans filtre date — les classes filles n'ont
     * pas à différencier).
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function fetchLive(?int $year, ?int $month, ?int $day, int $limit): array;

    /**
     * Snapshot stats persisté côté Last.fm ou Navidrome — renvoie null
     * quand il n'a jamais été calculé.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function fetchSnapshot(): ?array;

    /** Ex. `top_tracks_alltime`, `top_albums_alltime`, `top_artists_alltime`. */
    abstract protected function snapshotKey(): string;

    /** @return list<string> */
    abstract protected function availableYears(): array;

    abstract protected function templateName(): string;

    /** Affichée dans le bandeau ambre quand le snapshot manque. */
    abstract protected function computeCommand(): string;

    protected function renderTopList(Request $request): Response
    {
        $c = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );
        $hasFilter = $c['year'] !== null;

        $source = $hasFilter ? 'live' : 'snapshot';
        $computedAt = null;
        if ($hasFilter) {
            $rows = $this->fetchLive($c['year'], $c['month'], $c['day'], static::TOP_N);
        } else {
            $snapshot = $this->fetchSnapshot();
            $key = $this->snapshotKey();
            $cached = is_array($snapshot) && isset($snapshot[$key]) && is_array($snapshot[$key])
                ? $snapshot[$key]
                : null;
            if ($cached !== null) {
                /** @var list<array<string, mixed>> $rows */
                $rows = $cached;
                $computedAt = is_array($snapshot) && is_string($snapshot['computed_at'] ?? null)
                    ? $snapshot['computed_at']
                    : null;
            } else {
                $rows = $this->fetchLive(null, null, null, static::TOP_N);
                $source = 'live_fallback';
            }
        }

        return $this->render($this->templateName(), [
            'rows' => $rows,
            'top_n' => static::TOP_N,
            'available_years' => $this->availableYears(),
            'filters' => [
                'year' => $c['year'] !== null ? (string) $c['year'] : '',
                'month' => $c['month'] !== null ? sprintf('%02d', $c['month']) : '',
                'day' => $c['day'] !== null ? sprintf('%02d', $c['day']) : '',
            ],
            'source' => $source,
            'computed_at' => $computedAt,
            'compute_command' => $this->computeCommand(),
        ]);
    }
}
