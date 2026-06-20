<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Events;

use App\Domain\Contracts\Models\Document;

/**
 * Dispatched when a TerminationAgreement Document transitions to Signed status
 * (i.e. a signed_scan attachment is present and the operator marks it signed).
 *
 * N6-crm will register a listener that calls CompanyService::disconnect().
 * The Contracts domain only emits — it does NOT touch CRM state.
 *
 * Payload intentionally minimal: IDs + scalar timestamp so listeners can
 * reload fresh models from their own domain without coupling to this Document.
 */
class TerminationAgreementSigned
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $companyId,
        public readonly \DateTimeInterface $signedAt,
    ) {}

    public static function fromDocument(Document $doc): self
    {
        return new self(
            documentId: (int) $doc->id,
            companyId: (int) $doc->source_company_id,
            signedAt: $doc->signed_at ?? now(),
        );
    }
}
