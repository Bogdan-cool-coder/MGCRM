<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\AcquisitionChannelHistory;

/**
 * Records acquisition channel changes for companies and contacts.
 * Called from CompanyService and ContactService whenever acquisition_channel_id changes.
 */
class AcquisitionChannelHistoryService
{
    /**
     * Write a history record if old ≠ new (no-op when unchanged).
     *
     * @param  'company'|'contact'  $entityType
     */
    public function record(
        string $entityType,
        int $entityId,
        ?int $oldChannelId,
        ?int $newChannelId,
        ?int $userId,
    ): void {
        // Do not write a record if the channel did not actually change.
        if ($oldChannelId === $newChannelId) {
            return;
        }

        AcquisitionChannelHistory::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_channel_id' => $oldChannelId,
            'new_channel_id' => $newChannelId,
            'changed_by' => $userId,
            'changed_at' => now(),
        ]);
    }
}
