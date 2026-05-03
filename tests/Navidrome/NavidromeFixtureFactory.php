<?php

namespace App\Tests\Navidrome;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Builds a minimal Navidrome-compatible SQLite DB on disk for tests.
 */
final class NavidromeFixtureFactory
{
    public static function createDatabase(string $path, bool $withScrobbles = true): Connection
    {
        if (file_exists($path)) {
            unlink($path);
        }
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
        ]);

        $conn->executeStatement(<<<'SQL'
            CREATE TABLE user (
                id VARCHAR(255) PRIMARY KEY NOT NULL,
                user_name VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) DEFAULT '' NOT NULL,
                email VARCHAR(255) DEFAULT '' NOT NULL,
                password VARCHAR(255) DEFAULT '' NOT NULL,
                is_admin BOOL DEFAULT 0 NOT NULL,
                last_login_at DATETIME,
                last_access_at DATETIME,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        SQL);
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE media_file (
                id VARCHAR(255) PRIMARY KEY NOT NULL,
                path VARCHAR(1024) DEFAULT '' NOT NULL,
                title VARCHAR(255) DEFAULT '' NOT NULL,
                album VARCHAR(255) DEFAULT '' NOT NULL,
                artist VARCHAR(255) DEFAULT '' NOT NULL,
                artist_id VARCHAR(255) DEFAULT '' NOT NULL,
                album_artist VARCHAR(255) DEFAULT '' NOT NULL,
                album_id VARCHAR(255) DEFAULT '' NOT NULL,
                duration INTEGER DEFAULT 0 NOT NULL,
                year INTEGER DEFAULT 0 NOT NULL,
                genre VARCHAR(255) DEFAULT '' NOT NULL,
                mbz_track_id VARCHAR(255) DEFAULT '',
                mbz_recording_id VARCHAR(255) DEFAULT ''
            )
        SQL);
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE annotation (
                ann_id VARCHAR(255) PRIMARY KEY NOT NULL,
                user_id VARCHAR(255) DEFAULT '' NOT NULL,
                item_id VARCHAR(255) DEFAULT '' NOT NULL,
                item_type VARCHAR(255) DEFAULT '' NOT NULL,
                play_count INTEGER,
                play_date DATETIME,
                rating INTEGER,
                starred BOOL DEFAULT 0 NOT NULL,
                starred_at DATETIME
            )
        SQL);

        if ($withScrobbles) {
            // Mirror the real Navidrome 0.55+ schema where submission_time is
            // an INTEGER unix timestamp (was DATETIME prior to 0.55).
            // `client` (Subsonic client name: DSub, Symfonium, web…) is
            // optional in tests — left NULL when callers don't pass it.
            $conn->executeStatement(<<<'SQL'
                CREATE TABLE scrobbles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    media_file_id VARCHAR(255) NOT NULL,
                    user_id VARCHAR(255) NOT NULL,
                    submission_time INTEGER NOT NULL,
                    client VARCHAR(255) DEFAULT NULL
                )
            SQL);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO user (id, user_name, name, email, password, created_at, updated_at)
             VALUES ('user-1', 'admin', 'Admin', 'a@a', '', :now, :now)",
            ['now' => $now]
        );

        return $conn;
    }

    public static function insertTrack(
        Connection $conn,
        string $id,
        string $title,
        string $artist = 'Artist',
        int $duration = 180,
        string $album = 'Album',
        ?string $albumArtist = null,
        string $path = '',
        ?string $mbzTrackId = null,
        ?string $mbzRecordingId = null,
    ): void {
        $artistId = 'artist-' . md5($artist);
        $conn->executeStatement(
            'INSERT INTO media_file (id, path, title, artist, album, artist_id, album_artist, duration, mbz_track_id, mbz_recording_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $path,
                $title,
                $artist,
                $album,
                $artistId,
                $albumArtist ?? $artist,
                $duration,
                $mbzTrackId,
                $mbzRecordingId,
            ],
        );
    }

    public static function insertAnnotation(Connection $conn, string $userId, string $mediaId, int $playCount, string $playDate): void
    {
        $conn->executeStatement(
            'INSERT INTO annotation (ann_id, user_id, item_id, item_type, play_count, play_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(8)), $userId, $mediaId, 'media_file', $playCount, $playDate],
        );
    }

    public static function insertScrobble(
        Connection $conn,
        string $userId,
        string $mediaId,
        string $time,
        ?string $client = null,
    ): void {
        $ts = strtotime($time);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid scrobble time "%s".', $time));
        }
        $conn->executeStatement(
            'INSERT INTO scrobbles (media_file_id, user_id, submission_time, client) VALUES (?, ?, ?, ?)',
            [$mediaId, $userId, $ts, $client],
            [2 => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }
}
