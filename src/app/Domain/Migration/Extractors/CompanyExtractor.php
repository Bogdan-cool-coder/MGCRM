<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * CompanyExtractor — fetches the companies referenced by leads, in id batches of
 * 250, into companies.jsonl. Ids come from the sidecar collected by LeadExtractor.
 *
 * Temporary migration bounded-context (dropped at M12).
 */
class CompanyExtractor extends AbstractIdBatchExtractor
{
    public function entityName(): string
    {
        return 'companies';
    }

    protected function path(): string
    {
        return '/companies';
    }

    protected function idSidecar(): string
    {
        return 'companies';
    }

    protected function embeddedKey(): string
    {
        return 'companies';
    }

    protected function batchConfigKey(): string
    {
        return 'companies';
    }
}
