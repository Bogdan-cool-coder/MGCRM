<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a deal's owner_user_id changes (Deal Create 2.0 §5.3/§5.4,
 * docs/specs/deal-create-2-contract.md) — from DealService::create() (a new
 * deal's owner "claims" its company/contacts) and DealService::update() (an
 * explicit reassignment). NOT fired on a no-op (owner unchanged / self-assign).
 *
 * The single trigger for the Sales → Crm owner-sync rule ("owner компании и
 * контактов сделки следует за owner СДЕЛКИ"): SyncDealOwnershipListener
 * (Crm\Listeners) subscribes and calls CompanyService::syncOwnerFromDeal /
 * ContactService::syncOwnerFromDeal for the deal's company + linked contacts.
 * Deliberately a plain event (no ShouldBroadcast) — this is an internal
 * cross-domain fan-out signal, not a realtime UI contract.
 */
class DealOwnerChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $dealId,
        public readonly int $newOwnerId,
        public readonly ?int $previousOwnerId,
        public readonly ?User $actor = null,
    ) {}
}
