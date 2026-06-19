<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm_saved_views — server-persisted list/table configuration views.
 *
 * Stores user-defined column sets, sort, density and filter snapshots for the
 * Contact and Company list pages. Replaces localStorage-only approach so views
 * survive browser clears and are sharable across the team (is_shared).
 *
 * entity_type: 'contact' | 'company'  — matches the list page that owns it.
 * is_shared:   visible + usable by all team members.
 * is_default:  auto-applied when the user opens the list (per user × entity_type;
 *              enforced by SavedViewService, not a DB UNIQUE to avoid multi-row ops).
 * payload:     JSON blob — { columns: string[], sort: {field,dir}, density: string,
 *                            filters: Record<string,any> }.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_saved_views', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('entity_type'); // 'contact' | 'company'
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('payload'); // { columns, sort, density, filters }
            $table->timestamps();

            $table->index(['user_id', 'entity_type']);
            $table->index(['entity_type', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_views');
    }
};
