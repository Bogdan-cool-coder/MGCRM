<?php

declare(strict_types=1);

namespace App\Domain\Activity\Support;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared channel-set derivation for Activity broadcast events (Phase 7a).
 *
 * Every activity event (created / status-changed / updated / deleted) fans out
 * to the SAME three surfaces, so the mapping lives here once instead of being
 * re-derived in each event:
 *   - the target entity channel (deal./company./contact.) — drives the live
 *     card feed. Standalone (target-less) personal tasks have no entity channel.
 *   - the responsible user's personal channel (user.{id}) — drives their live
 *     "my tasks" list. Skipped when unassigned.
 *   - the department task channel (dept.{id}.tasks) — drives the team task list
 *     under the M9 department-visibility model. Skipped when the activity has no
 *     department anchor.
 *
 * @return list<PrivateChannel>
 */
trait BroadcastsActivityChannels
{
    /** @return list<PrivateChannel> */
    protected function activityChannels(Activity $activity): array
    {
        $channels = [];

        // Target entity channel (present only for a targeted activity).
        $targetType = $activity->target_type !== null
            ? ActivityTargetType::tryFrom((string) $activity->target_type)
            : null;

        if ($targetType !== null && $activity->target_id !== null) {
            $prefix = match ($targetType) {
                ActivityTargetType::Deal => 'deal',
                ActivityTargetType::Company => 'company',
                ActivityTargetType::Contact => 'contact',
            };
            $channels[] = new PrivateChannel($prefix.'.'.(int) $activity->target_id);
        }

        // Responsible user's personal channel (live "my tasks").
        if ($activity->responsible_id !== null) {
            $channels[] = new PrivateChannel('user.'.(int) $activity->responsible_id);
        }

        // Department task list channel.
        if ($activity->department_id !== null) {
            $channels[] = new PrivateChannel('dept.'.(int) $activity->department_id.'.tasks');
        }

        return $channels;
    }

    /**
     * Lean, PII-safe payload: ids + type + minimal display fields. The frontend
     * refetches the full record (or patches from these fields); we never ship
     * note/body text or PII over the socket.
     *
     * @return array<string, mixed>
     */
    protected function activityPayload(Activity $activity): array
    {
        return [
            'id' => (int) $activity->id,
            'kind' => $activity->kind instanceof \BackedEnum ? $activity->kind->value : $activity->kind,
            'status' => $activity->status instanceof \BackedEnum ? $activity->status->value : $activity->status,
            'target_type' => $activity->target_type instanceof \BackedEnum
                ? $activity->target_type->value
                : $activity->target_type,
            'target_id' => $activity->target_id !== null ? (int) $activity->target_id : null,
            'responsible_id' => $activity->responsible_id !== null ? (int) $activity->responsible_id : null,
            'department_id' => $activity->department_id !== null ? (int) $activity->department_id : null,
        ];
    }
}
