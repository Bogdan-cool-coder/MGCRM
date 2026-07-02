<?php

declare(strict_types=1);

namespace App\Domain\Crm\Support;

use App\Domain\Crm\Models\Company;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared channel-set + payload derivation for Company broadcast events (Phase 7a).
 *
 * Every company event (created / updated / deleted) fans out to:
 *   - the company entity channel (company.{id}) — live company-card feed;
 *   - the department contacts channel (dept.{id}.contacts) — live contact/company
 *     list under the M9 department-visibility model. Company carries its own
 *     department_id anchor; skipped when null.
 */
trait BroadcastsCompanyChannels
{
    /** @return list<PrivateChannel> */
    protected function companyChannels(Company $company): array
    {
        $channels = [new PrivateChannel('company.'.(int) $company->id)];

        if ($company->department_id !== null) {
            $channels[] = new PrivateChannel('dept.'.(int) $company->department_id.'.contacts');
        }

        return $channels;
    }

    /**
     * Lean, PII-safe payload: ids only (+ the routing owner/department). The list
     * views refetch the row; company name/requisites never ride the socket.
     *
     * @return array<string, mixed>
     */
    protected function companyPayload(Company $company): array
    {
        return [
            'id' => (int) $company->id,
            'owner_user_id' => $company->owner_user_id !== null ? (int) $company->owner_user_id : null,
            'department_id' => $company->department_id !== null ? (int) $company->department_id : null,
        ];
    }
}
