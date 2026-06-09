<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor a document_template chat to its target template:
 *   - document_id => document_template chat (mirrors report_id for
 *                    report_generation chats and widget_id for
 *                    widget_generation chats).
 *
 * `type` ('document_template') and `scope_type` ('document') are NOT touched
 * here — they stay varchar with model-level validation (see Chat::SCOPES and
 * the add_scope_type_to_chats migration). New values need no DDL.
 *
 * Must run AFTER document_templates (2026_05_27_000001) — FK target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->nullable()
                ->after('dashboard_id')
                ->constrained('document_templates')
                ->nullOnDelete();

            // Mirror chats_dashboard_scope_idx / chats_widget_idx for the
            // document_template chat resume / lookup query:
            // WHERE user_id=? AND company_id=? AND scope_type='document'
            //       AND document_id=? ORDER BY updated_at DESC
            $table->index(
                ['user_id', 'company_id', 'scope_type', 'document_id', 'updated_at'],
                'chats_document_scope_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('chats_document_scope_idx');
            // dropConstrainedForeignId drops both the FK and the column.
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
