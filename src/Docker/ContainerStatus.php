<?php

namespace App\Docker;

enum ContainerStatus: string
{
    case Disabled = 'disabled';
    case Running = 'running';
    case Stopped = 'stopped';
    case NotFound = 'notfound';
    case Unknown = 'unknown';

    public function isSafeToWrite(): bool
    {
        return match ($this) {
            self::Running, self::Unknown => false,
            self::Stopped, self::NotFound, self::Disabled => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Désactivé',
            self::Running => 'En cours',
            self::Stopped => 'Arrêté',
            self::NotFound => 'Introuvable',
            self::Unknown => 'Indéterminé',
        };
    }
}
