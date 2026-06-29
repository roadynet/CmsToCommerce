<?php

declare(strict_types=1);

namespace App\Enum;

enum AssetType: string
{
    case Image = 'image';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Bild',
            self::Document => 'Dokument',
        };
    }
}
