<?php

declare(strict_types=1);

namespace App\Enum;

enum ExternalSystemType: string
{
    case Generic = 'generic';
    case Jtl = 'jtl';
    case Plentymarkets = 'plentymarkets';
    case Xentral = 'xentral';
    case SapR3 = 'sap_r3';

    public function label(): string
    {
        return match ($this) {
            self::Generic => 'Generisches API-System',
            self::Jtl => 'JTL',
            self::Plentymarkets => 'plentymarkets',
            self::Xentral => 'Xentral',
            self::SapR3 => 'SAP R/3',
        };
    }
}
