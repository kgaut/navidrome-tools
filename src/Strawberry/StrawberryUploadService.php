<?php

namespace App\Strawberry;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manages an optionally uploaded Strawberry SQLite database stored at
 * var/strawberry_upload/strawberry.db inside the project directory.
 *
 * The uploaded file is independent of STRAWBERRY_DB_PATH — it lets users
 * who run Strawberry on a different machine sync scrobbles by uploading the
 * DB, running the processor, then downloading the updated file.
 */
class StrawberryUploadService
{
    private const UPLOAD_DIR = 'strawberry_upload';
    private const UPLOAD_FILENAME = 'strawberry.db';

    // SQLite 3 magic bytes: "SQLite format 3\000"
    private const SQLITE_MAGIC = "SQLite format 3\x00";

    public function __construct(private readonly string $varDir)
    {
    }

    public function getUploadPath(): string
    {
        return $this->varDir . '/' . self::UPLOAD_DIR . '/' . self::UPLOAD_FILENAME;
    }

    public function hasUpload(): bool
    {
        return file_exists($this->getUploadPath());
    }

    /**
     * Returns info about the uploaded file, or null if none.
     *
     * @return array{size: int, mtime: \DateTimeImmutable}|null
     */
    public function getUploadInfo(): ?array
    {
        $path = $this->getUploadPath();
        if (!file_exists($path)) {
            return null;
        }

        return [
            'size' => (int) filesize($path),
            'mtime' => new \DateTimeImmutable('@' . filemtime($path)),
        ];
    }

    /**
     * Validates and saves an uploaded file. Throws on validation failure.
     *
     * @throws \InvalidArgumentException if the file is not a valid SQLite 3 database
     */
    public function save(UploadedFile $file): void
    {
        $tmpPath = $file->getRealPath();
        if ($tmpPath === false) {
            throw new \InvalidArgumentException('Fichier temporaire inaccessible.');
        }

        $this->validateSqlite($tmpPath);

        $dir = $this->varDir . '/' . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, self::UPLOAD_FILENAME);
    }

    public function delete(): void
    {
        $path = $this->getUploadPath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function validateSqlite(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('Impossible de lire le fichier.');
        }

        try {
            $magic = fread($handle, 16);
        } finally {
            fclose($handle);
        }

        if ($magic !== self::SQLITE_MAGIC) {
            throw new \InvalidArgumentException(
                'Le fichier ne semble pas être une base SQLite 3 valide (magic bytes incorrects).',
            );
        }
    }
}
