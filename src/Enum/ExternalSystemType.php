<?php

declare(strict_types=1);

namespace App\Enum;

enum ExternalSystemType: string
{
    case Generic = 'generic';
    case Jtl = 'jtl';
    case Plentymarkets = 'plentymarkets';
    case Xentral = 'xentral';

    public function label(): string
    {
        return match ($this) {
            self::Generic => 'Generisches API-System',
            self::Jtl => 'JTL',
            self::Plentymarkets => 'plentymarkets',
            self::Xentral => 'Xentral',
        };
    }
}
