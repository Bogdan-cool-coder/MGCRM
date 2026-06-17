<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\Enums\ChannelKind;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum tests — no framework boot needed (extends PHPUnit base directly).
 */
class ChannelKindTest extends TestCase
{
    public function test_default_lead_source_map(): void
    {
        $this->assertSame('tg', ChannelKind::Tg->defaultLeadSource());
        $this->assertSame('wa', ChannelKind::Wa->defaultLeadSource());
        $this->assertSame('email', ChannelKind::Email->defaultLeadSource());
        $this->assertSame('form', ChannelKind::WebForm->defaultLeadSource());
        $this->assertSame('api', ChannelKind::Api->defaultLeadSource());
    }

    public function test_only_tg_wa_dedup_by_channel_identifier(): void
    {
        $this->assertTrue(ChannelKind::Tg->dedupsByChannelIdentifier());
        $this->assertTrue(ChannelKind::Wa->dedupsByChannelIdentifier());
        $this->assertFalse(ChannelKind::Email->dedupsByChannelIdentifier());
        $this->assertFalse(ChannelKind::WebForm->dedupsByChannelIdentifier());
        $this->assertFalse(ChannelKind::Api->dedupsByChannelIdentifier());
    }
}
