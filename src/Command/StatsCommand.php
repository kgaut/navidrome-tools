<?php

namespace App\Command;

use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stats',
    description: 'Display a summary of Last.fm and Navidrome statistics.',
)]
class StatsCommand extends Command
{
    public function __construct(
        private readonly ScrobbleRepository $scrobbles,
        private readonly NavidromeRepository $navidrome,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Last.fm username (defaults to LASTFM_USER env).', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $input->getOption('user') ?? ($this->defaultUser !== '' ? $this->defaultUser : null);

        $io->title('Statistiques d\'écoute');

        // ── Last.fm (base locale) ──────────────────────────────────────
        $io->section('Last.fm (base locale)' . ($user !== null ? ' — ' . $user : ''));

        $totalScrobbles = $user !== null ? $this->scrobbles->countByUser($user) : $this->scrobbles->countAll();
        $bounds = $this->scrobbles->getScrobbleBounds($user);
        $lovedCount = $this->scrobbles->countLoved($user);
        $distinctArtists = $this->scrobbles->countDistinctArtists($user);
        $distinctTracks = $this->scrobbles->countDistinctTracks($user);

        $io->definitionList(
            ['Scrobbles' => self::fmt($totalScrobbles)],
            ['Plus ancien' => self::fmtDate($bounds['first'])],
            ['Plus récent' => self::fmtDate($bounds['last'])],
            ['Coups de cœur' => self::fmt($lovedCount)],
            ['Artistes distincts' => self::fmt($distinctArtists)],
            ['Titres distincts' => self::fmt($distinctTracks)],
        );

        // ── Navidrome ─────────────────────────────────────────────────
        $io->section('Navidrome');

        if (!$this->navidrome->isAvailable()) {
            $io->warning('Base Navidrome inaccessible.');
            return Command::SUCCESS;
        }

        $library = $this->navidrome->getLibraryCounts();
        $starred = $this->navidrome->getStarredCounts();
        $totalPlays = $this->navidrome->getTotalPlays(null, null);
        $navBounds = $this->navidrome->getScrobbleBounds();
        $distinctPlayed = $this->navidrome->getDistinctTracksPlayed(null, null);

        $playedPct = $library['tracks'] > 0
            ? sprintf(' (%.1f %%)', 100 * $distinctPlayed / $library['tracks'])
            : '';

        $io->definitionList(
            ['Lectures totales' => self::fmt($totalPlays)],
            ['Plus ancienne' => self::fmtDate($navBounds['first'])],
            ['Plus récente' => self::fmtDate($navBounds['last'])],
            ['Morceaux écoutés au moins 1×' => self::fmt($distinctPlayed) . $playedPct],
            ['Favoris (★) morceaux' => self::fmt($starred['tracks'])],
            ['Favoris (★) albums' => self::fmt($starred['albums'])],
            ['Favoris (★) artistes' => self::fmt($starred['artists'])],
        );

        $io->section('Bibliothèque Navidrome');
        $io->definitionList(
            ['Morceaux' => self::fmt($library['tracks'])],
            ['Artistes' => self::fmt($library['artists'])],
            ['Albums' => self::fmt($library['albums'])],
            ['Durée totale' => self::fmtDuration($library['duration_seconds'])],
        );

        return Command::SUCCESS;
    }

    private static function fmt(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private static function fmtDate(?\DateTimeImmutable $dt): string
    {
        if ($dt === null) {
            return '—';
        }
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i');
    }

    private static function fmtDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%d j %dh %02d min', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %02d min', $hours, $minutes);
        }

        return sprintf('%d min', $minutes);
    }
}