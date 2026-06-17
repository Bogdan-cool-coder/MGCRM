<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2.8 hard won-gate delta: one reversible column on pipeline_stages.
 *
 *  - won_gate_contract_required bool default(true) — when a stage has won_gate on,
 *    this flag says the gate requires a "live" contract (approved/signed/uploaded)
 *    on the deal before it can enter the stage. Default true makes existing
 *    won-stages hard out of the box (DealMoveService enforces a 409 otherwise).
 *
 * Two distinct flags by design (S2.8 plan B.1): won_gate = "this stage is a real
 * win"; won_gate_contract_required = "what the gate demands". No index — the flag
 * is only read for the single already-resolved target stage of a move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->boolean('won_gate_contract_required')->default(true)->after('won_gate');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn('won_gate_contract_required');
        });
    }
};
