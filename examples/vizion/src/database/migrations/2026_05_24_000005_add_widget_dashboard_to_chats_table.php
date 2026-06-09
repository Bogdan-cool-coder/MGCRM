<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor new chat kinds to their target entity:
 *   - widget_id    => widget_generation chat (mirrors report_id for
 *                     report_generation chats).
 *   - dashboard_id => scope=dashboard mini-chat (quick_qa over a dashboard's
 *                     widget configs).
 *
 * `type` ('widget_generation') and `scope_type` ('dashboard') are NOT touched
 * here — they stay varchar with model-level validation (see Chat::SCOPES and
 * the add_scope_type_to_chats migration). New values need no DDL.
 *
 * Must run AFTER widgets (000002) and dashboards (000003) — FK targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('widget_id')
                ->nullable()
                ->after('report_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('dashboard_id')
                ->nullable()
                ->after('widget_id')
                ->constrained()
                ->nullOnDelete();

            // Mirror chats_scope_lookup_idx for the dashboard mini-chat resume
            // query: WHERE user_id=? AND company_id=? AND scope_type='dashboard'
            //        AND dashboard_id=? ORDER BY updated_at DESC
            $table->index(
                ['user_id', 'company_id', 'scope_type', 'dashboard_id', 'updated_at'],
                'chats_dashboard_scope_idx'
            );
            // widget_generation chat lookup by widget.
            $table->index(
                ['user_id', 'company_id', 'widget_id', 'updated_at'],
                'chats_widget_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('chats_dashboard_scope_idx');
            $table->dropIndex('chats_widget_idx');
            // dropConstrainedForeignId drops both the FK and the column.
            $table->dropConstrainedForeignId('widget_id');
            $table->dropConstrainedForeignId('dashboard_id');
        });
    }
};
