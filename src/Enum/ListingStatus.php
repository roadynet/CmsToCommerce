<?php

declare(strict_types=1);

namespace App\Enum;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case ReadyToPublish = 'ready_to_publish';
    case Published = 'published';
    case SyncError = 'sync_error';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Validated => 'Geprüft',
            self::ReadyToPublish => 'Bereit zur Veröffentlichung',
            self::Published => 'Veröffentlicht',
            self::SyncError => 'Synchronisationsfehler',
        };
    }
}
