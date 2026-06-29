<?php

declare(strict_types=1);

namespace App\Enum;

enum ChannelType: string
{
    case Amazon = 'amazon';
    case Shopware = 'shopware';

    public function label(): string
    {
        return match ($this) {
            self::Amazon => 'Amazon',
            self::Shopware => 'Shopware',
        };
    }
}
