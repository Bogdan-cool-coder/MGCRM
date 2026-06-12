<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DealMoveService — the ONLY path to change a deal's stage (security boundary).
 *
 * Move is transactional + row-locked, idempotent (no-op when already in the
 * target stage), gated by lost-reason (hard 422) and won-gate (soft warning in
 * S1.3 — see Q3), and writes DealStageHistory plus stage_changed_at/closed_at.
 *
 * Result shape: ['deal' => Deal, 'won_gate_warning' => bool].
 */
class DealMoveService
{
    /**
     * @return array{deal: Deal, won_gate_warning: bool}
     */
    public function move(
        Deal $deal,
        int $toStageId,
        int $userId,
        ?string $lostReason = null,
        ?int $lostReasonId = null,
    ): array {
        return DB::transaction(function () use ($deal, $toStageId, $userId, $lostReason, $lostReasonId): array {
            // 1. Row-lock the deal (anti double-drag race).
            $locked = Deal::query()->lockForUpdate()->findOrFail($deal->id);

            // 2. Target stage must belong to the same pipeline.
            $toStage = PipelineStage::query()->find($toStageId);
            if ($toStage === null || (int) $toStage->pipeline_id !== (int) $locked->pipeline_id) {
                throw ValidationException::withMessages([
                    'to_stage_id' => 'Target stage does not belong to this deal\'s pipeline.',
                ]);
            }

            // 3. Idempotency: already in the target stage → no-op (no history row).
            if ((int) $locked->stage_id === (int) $toStage->id) {
                return ['deal' => $locked->load('stage'), 'won_gate_warning' => false];
            }

            // 4. Lost-gate: entering a lost stage requires a reason (text or FK).
            if ($toStage->is_lost && $lostReason === null && $lostReasonId === null) {
                throw ValidationException::withMessages([
                    'lost_reason' => 'A lost reason is required to move a deal to a lost stage.',
                ])->status(422);
            }

            // 5. Won-gate: soft in S1.3 (warning only; hard 409 lands in S2).
            $wonGateWarning = false;
            if ($toStage->won_gate) {
                $wonGateWarning = ! $this->canPassWonGate($locked);
            }

            // 6. Apply the transition.
            $fromStageId = (int) $locked->stage_id;
            $locked->stage_id = $toStage->id;
            $locked->stage_changed_at = now();

            if ($toStage->is_won || $toStage->is_lost) {
                $locked->closed_at = now();
            } else {
                $locked->closed_at = null;
            }

            if ($toStage->is_lost) {
                $locked->lost_reason = $lostReason;
                $locked->lost_reason_id = $lostReasonId;
            } else {
                // Leaving a lost stage clears the loss attribution.
                $locked->lost_reason = null;
                $locked->lost_reason_id = null;
            }

            $locked->save();

            // 7. Append-only history row (stable event contract).
            DealStageHistory::create([
                'deal_id' => $locked->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStage->id,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            return ['deal' => $locked->load('stage'), 'won_gate_warning' => $wonGateWarning];
        });
    }

    /**
     * Won-gate check (S1.3 stub). Passes if a contract is attached; otherwise
     * the move still proceeds but the caller surfaces a soft warning. The hard
     * gate (signed contract / payment) lands once Contract/Finance exist (S2/M9).
     */
    private function canPassWonGate(Deal $deal): bool
    {
        return $deal->contract_id !== null;
    }
}
