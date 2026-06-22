<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Потенциальный',
            self::Active => 'Действующий клиент',
            self::Disconnected => 'Отключён',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
