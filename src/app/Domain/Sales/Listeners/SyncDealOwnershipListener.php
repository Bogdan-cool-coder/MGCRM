<?php

declare(strict_types=1);

namespace App\Domain\Sales\Listeners;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Sales\Events\DealOwnerChanged;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;

/**
 * SyncDealOwnershipListener (Deal Create 2.0 §5.3/§5.4,
 * docs/specs/deal-create-2-contract.md) — Rule A: "owner компании и контактов
 * сделки следует за owner СДЕЛКИ". Subscribes to DealOwnerChanged (fired by
 * DealService::create()/update() in the Sales domain) and re-points the deal's
 * company + every linked contact's owner at the deal's (new) owner.
 *
 * Lives in Sales (the event's owning domain) but writes ONLY through the Crm
 * services (CompanyService::syncOwnerFromDeal / ContactService::syncOwnerFromDeal)
 * — Sales never sets `companies.owner_user_id` / `crm_contacts.owner_id`
 * directly (DDD boundary, contract §5.4).
 *
 * Edge cases (contract §5.6):
 *   - Self-assign / already-synced target → the Crm service methods are
 *     idempotent (no-op, no log row) — nothing extra to guard here.
 *   - Deal deleted / company vanished mid-flight → Deal::find / Company::find
 *     null-check, silent no-op (a queued listener must never fail loudly on a
 *     stale reference).
 *   - Several open deals on one company → "last write wins" is the accepted
 *     semantics (contract §5.6): whichever DealOwnerChanged fires last wins,
 *     no special-casing needed.
 */
class SyncDealOwnershipListener
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly ContactService $contacts,
    ) {}

    public function handle(DealOwnerChanged $event): void
    {
        $deal = Deal::find($event->dealId);

        if ($deal === null) {
            return;
        }

        if ($deal->company_id !== null) {
            $company = Company::find($deal->company_id);

            if ($company !== null) {
                $this->companies->syncOwnerFromDeal($company, $event->newOwnerId, $event->actor);
            }
        }

        $contactIds = DealContact::query()
            ->where('deal_id', $deal->id)
            ->pluck('contact_id');

        if ($contactIds->isEmpty()) {
            return;
        }

        $contacts = Contact::query()->whereIn('id', $contactIds)->get();

        foreach ($contacts as $contact) {
            $this->contacts->syncOwnerFromDeal($contact, $event->newOwnerId, $event->actor);
        }
    }
}
