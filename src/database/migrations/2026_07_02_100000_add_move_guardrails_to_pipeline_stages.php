<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M7 stage-move guardrails: two reversible per-stage boolean columns on
 * pipeline_stages (prod-readiness — a deal must not reach Won with amount=0, and
 * stages must not be skippable at will).
 *
 *  - won_gate_amount_required bool default(false) — when a WON stage carries this
 *    flag, DealMoveService refuses (422) a move into it while the deal amount is
 *    <= 0. Independent of won_gate / won_gate_contract_required so it protects
 *    pipelines where the contract gate is off.
 *  - allow_stage_skip bool default(true) — when FALSE on a target stage, a
 *    forward skip into it (sort_order > from + 1, excluding backward moves and
 *    won/lost terminals) is refused (422). Default true preserves today's freedom
 *    to drag anywhere; an admin opts into skip-blocking per stage later.
 *
 * Activation (plan step 4): flip won_gate_amount_required=true on every existing
 * is_won stage so "нет суммы — нет выигрыша" is live on the MACRO Global funnel
 * out of the box. allow_stage_skip stays true everywhere (opt-in per stage).
 * No index — both flags are read only for the single resolved target stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->boolean('won_gate_amount_required')->default(false)->after('won_gate_contract_required');
            $table->boolean('allow_stage_skip')->default(true)->after('won_gate_amount_required');
        });

        // Activate "no win without amount" on all existing won stages (live funnels).
        DB::table('pipeline_stages')
            ->where('is_won', true)
            ->update(['won_gate_amount_required' => true]);
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn(['won_gate_amount_required', 'allow_stage_skip']);
        });
    }
};
