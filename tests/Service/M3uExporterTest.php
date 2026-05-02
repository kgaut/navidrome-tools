<?php

namespace App\Tests\Service;

use App\Navidrome\TrackSummary;
use App\Service\M3uExporter;
use PHPUnit\Framework\TestCase;

class M3uExporterTest extends TestCase
{
    public function testExportFromArrays(): void
    {
        $exporter = new M3uExporter();
        $body = $exporter->export([
            ['title' => 'Halo', 'artist' => 'Beyoncé', 'duration' => 200, 'path' => 'B/I Am/01 - Halo.mp3'],
            ['title' => 'Smile', 'artist' => 'Lily Allen', 'duration' => 160, 'path' => 'L/Alright Still/05 - Smile.mp3'],
        ]);

        $this->assertStringStartsWith("#EXTM3U\n", $body);
        $this->assertStringContainsString("#EXTINF:200,Beyoncé - Halo\n", $body);
        $this->assertStringContainsString("B/I Am/01 - Halo.mp3\n", $body);
        $this->assertStringContainsString("#EXTINF:160,Lily Allen - Smile\n", $body);
    }

    public function testExportFromTrackSummary(): void
    {
        $exporter = new M3uExporter();
        $body = $exporter->export([
            new TrackSummary('mf-1', 'Halo', 'Beyoncé', 'I Am', 200, 12),
        ]);

        // TrackSummary has no `path`, so we fall back to the title.
        $this->assertStringContainsString("#EXTINF:200,Beyoncé - Halo\n", $body);
        $this->assertStringContainsString("Halo\n", $body);
    }

    public function testExportStripsCommaInLabel(): void
    {
        $exporter = new M3uExporter();
        $body = $exporter->export([
            ['title' => 'Hello, World', 'artist' => 'A, B', 'duration' => 100, 'path' => 'p.mp3'],
        ]);

        $this->assertStringContainsString("#EXTINF:100,A B - Hello World\n", $body);
        $this->assertStringNotContainsString(',Hello, World', $body);
    }

    public function testExportEmptyListProducesHeaderOnly(): void
    {
        $exporter = new M3uExporter();
        $this->assertSame("#EXTM3U\n", $exporter->export([]));
    }

    public function testFilenameForSlugifies(): void
    {
        $exporter = new M3uExporter();
        $this->assertSame('Top-Rock.m3u', $exporter->filenameFor('Top Rock'));
        $this->assertSame('Top-100-2025.m3u', $exporter->filenameFor('Top 100 — 2025!'));
        $this->assertSame('playlist.m3u', $exporter->filenameFor('!!!'));
    }
}
