<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pipelines.default_stage_id — "Стадия для новых сделок" (Deal Create 2.0 §2.2,
 * §3): the stage instant-created deals land in, instead of always "the first
 * non-won/lost/hidden stage". nullable → falls back to that existing rule.
 *
 * Same shape as channels.default_stage_id (2026_06_16_100000_create_channels_table)
 * — nullable FK to pipeline_stages, nullOnDelete (deleting the stage silently
 * clears the setting rather than blocking the delete or orphaning the pipeline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            $table->foreignId('default_stage_id')->nullable()->after('settings')
                ->constrained('pipeline_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_stage_id');
        });
    }
};
