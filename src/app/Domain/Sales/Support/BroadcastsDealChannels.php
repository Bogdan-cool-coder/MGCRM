<?php

declare(strict_types=1);

namespace App\Domain\Sales\Support;

use App\Domain\Sales\Models\Deal;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared channel-set + payload derivation for Deal broadcast events (Phase 7a).
 *
 * Every deal event (created / updated / stage-changed / deleted) fans out to:
 *   - the deal entity channel (deal.{id}) — drives the live deal-card feed;
 *   - the department deals channel (dept.{id}.deals) — drives the live board /
 *     kanban list under the M9 department-visibility model. Skipped when the
 *     deal has no department anchor.
 */
trait BroadcastsDealChannels
{
    /** @return list<PrivateChannel> */
    protected function dealChannels(Deal $deal): array
    {
        $channels = [new PrivateChannel('deal.'.(int) $deal->id)];

        if ($deal->department_id !== null) {
            $channels[] = new PrivateChannel('dept.'.(int) $deal->department_id.'.deals');
        }

        return $channels;
    }

    /**
     * Lean, PII-safe payload: ids + stage/owner + amount (already integer
     * kopecks, no PII). The frontend refetches or patches the board card.
     *
     * @return array<string, mixed>
     */
    protected function dealPayload(Deal $deal): array
    {
        return [
            'id' => (int) $deal->id,
            'pipeline_id' => $deal->pipeline_id !== null ? (int) $deal->pipeline_id : null,
            'stage_id' => $deal->stage_id !== null ? (int) $deal->stage_id : null,
            'company_id' => $deal->company_id !== null ? (int) $deal->company_id : null,
            'owner_user_id' => $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null,
            'department_id' => $deal->department_id !== null ? (int) $deal->department_id : null,
            'amount' => $deal->amount !== null ? (int) $deal->amount : null,
        ];
    }
}
