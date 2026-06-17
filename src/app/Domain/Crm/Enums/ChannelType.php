<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum ChannelType: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Telegram = 'tg';
    case WhatsApp = 'wa';
    case LinkedIn = 'linkedin';
    case Instagram = 'instagram';
    case Viber = 'viber';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Email => 'Email',
            self::Telegram => 'Telegram',
            self::WhatsApp => 'WhatsApp',
            self::LinkedIn => 'LinkedIn',
            self::Instagram => 'Instagram',
            self::Viber => 'Viber',
        };
    }
}
