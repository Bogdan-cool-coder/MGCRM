<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_versions carries THREE indexes on the same leading columns:
 *   - uq_template_versions_template_version   UNIQUE (template_id, version_number)
 *   - ix_template_versions_template_version   plain  (template_id, version_number)  ← EXACT DUP
 *   - ix_template_versions_template           plain  (template_id)
 *
 * The plain ix_template_versions_template_version is an EXACT column-for-column
 * duplicate of the unique's backing btree — the unique index already serves every
 * lookup it would (including the leading-column template_id prefix), so it is pure
 * write-amplification + storage waste and is dropped here (same pattern as the
 * already-shipped salary_plans dedup, 2026_06_30_260000).
 *
 * Conservative scope: ONLY the exact-column-set duplicate is removed. The
 * leading-prefix single-column ix_template_versions_template is left in place — it
 * is a debatable removal (index size / index-only scan tradeoffs), not a clean dup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_versions', function (Blueprint $table): void {
            $table->dropIndex('ix_template_versions_template_version');
        });
    }

    public function down(): void
    {
        Schema::table('template_versions', function (Blueprint $table): void {
            $table->index(['template_id', 'version_number'], 'ix_template_versions_template_version');
        });
    }
};
