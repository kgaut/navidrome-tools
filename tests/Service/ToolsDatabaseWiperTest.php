<?php

namespace App\Tests\Service;

use App\Service\ToolsDatabaseWiper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class ToolsDatabaseWiperTest extends TestCase
{
    private string $dbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/tools-wiper-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath,
        ]);

        // Tables that must be wiped — minimal schemas, just the columns we touch.
        foreach (ToolsDatabaseWiper::wipedTables() as $table) {
            $this->conn->executeStatement(
                'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)',
            );
            $this->conn->executeStatement(
                'INSERT INTO ' . $table . " (payload) VALUES ('row-1'), ('row-2')",
            );
        }

        // Tables that must be preserved.
        foreach (['lastfm_alias', 'lastfm_artist_alias', 'setting', 'playlist_definition'] as $table) {
            $this->conn->executeStatement(
                'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)',
            );
            $this->conn->executeStatement(
                'INSERT INTO ' . $table . " (payload) VALUES ('keep-me')",
            );
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testWipeEmptiesTrackedTablesAndReportsCounts(): void
    {
        $wiper = new ToolsDatabaseWiper($this->conn);

        $deleted = $wiper->wipe();

        foreach (ToolsDatabaseWiper::wipedTables() as $table) {
            self::assertSame(2, $deleted[$table], $table . ' should report 2 rows deleted');
            self::assertSame(
                0,
                (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $table),
                $table . ' should be empty after wipe',
            );
        }
    }

    public function testWipePreservesAliasAndSettingTables(): void
    {
        $wiper = new ToolsDatabaseWiper($this->conn);

        $wiper->wipe();

        foreach (['lastfm_alias', 'lastfm_artist_alias', 'setting', 'playlist_definition'] as $table) {
            self::assertSame(
                1,
                (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $table),
                $table . ' must not be touched by the wipe',
            );
        }
    }

    public function testWipeIsIdempotent(): void
    {
        $wiper = new ToolsDatabaseWiper($this->conn);

        $wiper->wipe();
        $second = $wiper->wipe();

        foreach ($second as $count) {
            self::assertSame(0, $count);
        }
    }
}
