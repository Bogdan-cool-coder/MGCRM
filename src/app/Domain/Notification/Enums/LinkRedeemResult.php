<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * LinkRedeemResult (S2.9) — outcome of redeeming a TelegramLinkToken via
 * /start link_<token>. Drives the RU reply the bot sends back (no internal IDs
 * or token are ever leaked — §И security).
 */
enum LinkRedeemResult: string
{
    case Linked = 'linked';
    case Invalid = 'invalid';
    case AlreadyUsed = 'already_used';
    case Expired = 'expired';
    case LinkedToOther = 'linked_to_other';
}
