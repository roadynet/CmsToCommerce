<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Imported = 'imported';
    case Review = 'review';
    case Approved = 'approved';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Imported => 'Importiert',
            self::Review => 'In Prüfung',
            self::Approved => 'Freigegeben',
            self::Published => 'Veröffentlicht',
        };
    }
}
