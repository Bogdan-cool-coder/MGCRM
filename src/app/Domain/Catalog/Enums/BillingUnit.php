<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum BillingUnit: string
{
    case Year = 'year';
    case OneTime = 'one_time';
    case Minute = 'minute';
    case Package = 'package';
    case Perpetual = 'perpetual';

    public function label(): string
    {
        return match ($this) {
            self::Year => 'Год',
            self::OneTime => 'Единоразово',
            self::Minute => 'Минута',
            self::Package => 'Пакет',
            self::Perpetual => 'Вечная лицензия',
        };
    }
}
