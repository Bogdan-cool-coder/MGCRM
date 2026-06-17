<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1.5 editor delta: two reversible columns on pipeline_stages.
 *
 *  - task_types      json '[]'  — whitelist of ActivityType allowed on this stage
 *                                 (contract for S1.6; empty = all allowed).
 *  - required_fields json '{}'  — {"deal":[...], "company":[...]} fields required to
 *                                 ENTER this stage (checked in DealMoveService).
 *
 * Defaults backfill the existing 11 stages backward-compatibly (empty = no limit).
 * No new indexes — neither column is filtered at the DB level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->json('task_types')->default('[]')->after('stage_features');
            $table->json('required_fields')->default('{}')->after('task_types');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn(['task_types', 'required_fields']);
        });
    }
};
