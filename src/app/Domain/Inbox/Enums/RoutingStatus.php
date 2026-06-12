<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * Routing outcome of an inbound message.
 *
 *   routed → a Deal was created from this message.
 *   dedup  → external_id already routed → linked to the existing Deal, no new one.
 *   failed → no sales pipeline / `new` stage → Deal NOT created, needs manual
 *            triage (the Inbox UI surfaces these). The lead is never lost silently.
 */
enum RoutingStatus: string
{
    case Routed = 'routed';
    case Dedup = 'dedup';
    case Failed = 'failed';
}
