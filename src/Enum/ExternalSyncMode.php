<?php

declare(strict_types=1);

namespace App\Enum;

enum ExternalSyncMode: string
{
    case Upsert = 'upsert';
    case Delta = 'delta';

    public function label(): string
    {
        return match ($this) {
            self::Upsert => 'Upsert',
            self::Delta => 'Delta',
        };
    }
}
