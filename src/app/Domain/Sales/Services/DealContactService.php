<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DealContactService — link contacts to a deal (M2M). Adding a contact also
 * links it to the deal's company via the existing CrmContactService::linkCompany
 * (cross-domain through a public service method — never touching Crm tables).
 * At most one primary contact per deal (enforced by a partial unique index).
 */
class DealContactService
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * @return Collection<int, DealContact>
     */
    public function list(Deal $deal): Collection
    {
        return DealContact::query()
            ->where('deal_id', $deal->id)
            ->with('contact:id,full_name,position,email,phone')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * Attach a contact to the deal and ensure a ContactCompanyLink with the
     * deal's company. Duplicate (deal, contact) → 409. Primary uniqueness is
     * guaranteed by un-setting any existing primary inside the transaction.
     */
    public function addContact(Deal $deal, int $contactId, bool $isPrimary = false, ?User $actor = null): DealContact
    {
        return DB::transaction(function () use ($deal, $contactId, $isPrimary, $actor): DealContact {
            $exists = DealContact::query()
                ->where('deal_id', $deal->id)
                ->where('contact_id', $contactId)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'contact_id' => 'This contact is already linked to the deal.',
                ])->status(409);
            }

            if ($isPrimary) {
                DealContact::query()
                    ->where('deal_id', $deal->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $dealContact = DealContact::create([
                'deal_id' => $deal->id,
                'contact_id' => $contactId,
                'is_primary' => $isPrimary,
            ]);

            // Cross-domain: link contact to the deal's company via Crm service.
            $contact = Contact::find($contactId);
            if ($contact !== null) {
                $this->contacts->linkCompany($contact, (int) $deal->company_id, []);
            }

            // Polymorphic action log: a contact was linked to the deal (who/when
            // + the contact's name so the timeline reads without a lookup).
            $this->entityLog->record(
                LogSubjectType::Deal,
                (int) $deal->id,
                $actor,
                LogAction::ContactAdded,
                [
                    'contact_id' => $contactId,
                    'contact_name' => $contact?->full_name,
                    'is_primary' => $isPrimary,
                ],
            );

            return $dealContact->load('contact:id,full_name,position,email,phone');
        });
    }

    /**
     * Toggle the primary flag on a deal-contact link. Setting it primary demotes
     * any other primary on the same deal first (the partial unique index allows
     * one is_primary=true per deal, so the old one MUST be cleared before the new
     * one is set). Returns the deal's current links (primary first).
     *
     * @return Collection<int, DealContact>
     */
    public function setPrimary(Deal $deal, DealContact $dealContact, bool $isPrimary): Collection
    {
        DB::transaction(function () use ($deal, $dealContact, $isPrimary): void {
            if ($isPrimary) {
                // Demote any other primary BEFORE promoting this one, so the
                // partial unique index (one primary per deal) is never violated.
                DealContact::query()
                    ->where('deal_id', $deal->id)
                    ->where('id', '!=', $dealContact->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $dealContact->is_primary = $isPrimary;
            $dealContact->save();
        });

        return $this->list($deal);
    }

    public function removeContact(DealContact $dealContact): void
    {
        $dealContact->delete();
    }
}
