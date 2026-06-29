<?php

declare(strict_types=1);

namespace App\Enum;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ausstehend',
            self::Running => 'Läuft',
            self::Succeeded => 'Erfolgreich',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
