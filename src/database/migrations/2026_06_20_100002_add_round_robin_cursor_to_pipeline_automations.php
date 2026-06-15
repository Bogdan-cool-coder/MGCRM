<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * round_robin_cursor — persistent rotation cursor for the change_owner /
 * round_robin action (P1).
 *
 * The old project kept this counter in a generic key/value Setting table
 * (automation_round_robin_cursor:<id>). MGCRM has no such table, so the cursor
 * lives on the automation row itself — one column, no cross-domain dependency,
 * and the per-automation PG advisory lock + this column together serialise the
 * "read cursor → pick owner → advance cursor" critical section under scale-out
 * workers (see ChangeOwnerAction). round_robin_pick() reads cursor % pool_size,
 * so any monotonically-growing integer works as the cursor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_automations', function (Blueprint $table): void {
            $table->unsignedInteger('round_robin_cursor')->default(0)->after('action_config');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_automations', function (Blueprint $table): void {
            $table->dropColumn('round_robin_cursor');
        });
    }
};
