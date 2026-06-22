<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * ContactExtractor — fetches the contacts referenced by leads, in id batches of
 * 250, into contacts.jsonl. Ids come from the sidecar collected by LeadExtractor.
 *
 * Temporary migration bounded-context (dropped at M12).
 */
class ContactExtractor extends AbstractIdBatchExtractor
{
    public function entityName(): string
    {
        return 'contacts';
    }

    protected function path(): string
    {
        return '/contacts';
    }

    protected function idSidecar(): string
    {
        return 'contacts';
    }

    protected function embeddedKey(): string
    {
        return 'contacts';
    }

    protected function batchConfigKey(): string
    {
        return 'contacts';
    }
}
