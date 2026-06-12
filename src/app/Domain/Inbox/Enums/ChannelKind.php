<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * Channel kind — the type of inbound point. In S1.9 a channel only receives the
 * generic webhook (POST /inbox/webhook/{channel}); real TG/WA/Email connectors
 * (Bot API polling, WA Cloud, IMAP) land on the integrations sprint.
 *
 * defaultLeadSource() maps a kind to the Lead/Company source used when a channel
 * leaves default_lead_source blank (mirrors inbox.py LEAD_SOURCE_MAP).
 */
enum ChannelKind: string
{
    case Tg = 'tg';
    case Wa = 'wa';
    case Email = 'email';
    case WebForm = 'web_form';
    case Api = 'api';

    /**
     * Kinds whose company dedup is per-channel by from_identifier (chat_id /
     * username is neither an email nor a global phone — see E2). Other kinds
     * dedup by contact (email/phone).
     */
    public function dedupsByChannelIdentifier(): bool
    {
        return $this === self::Tg || $this === self::Wa;
    }

    /**
     * Default Lead/Company source for this kind (kind → source map).
     */
    public function defaultLeadSource(): string
    {
        return match ($this) {
            self::Tg => 'tg',
            self::Wa => 'wa',
            self::Email => 'email',
            self::WebForm => 'form',
            self::Api => 'api',
        };
    }
}
