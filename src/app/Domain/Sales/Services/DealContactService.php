<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
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
    public function addContact(Deal $deal, int $contactId, bool $isPrimary = false): DealContact
    {
        return DB::transaction(function () use ($deal, $contactId, $isPrimary): DealContact {
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

            return $dealContact->load('contact:id,full_name,position,email,phone');
        });
    }

    public function removeContact(DealContact $dealContact): void
    {
        $dealContact->delete();
    }
}
