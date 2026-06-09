<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_templates — config-entity, structural mirror of `widgets` / `reports`.
 * A template describes how to render a document (PDF/Word) from MacroData fields.
 * Two types: 'html' (branded commercial proposals via Gotenberg Chromium) and
 * 'docx' (uploaded Word template with ${placeholders}, M5+).
 *
 * Visibility model is identical to reports/widgets: system (is_system, visible to
 * everyone), published-to-company (is_published) or personal (author user_id).
 * `source_path` holds the uploaded docx path on disk local (used by the docx type
 * from M5). `config` is the jsonb render config (fields, mappings, html body).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null user_id => system template (no author).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            // 'html' | 'docx'
            $table->string('type', 8);
            $table->jsonb('config');
            // Path to an uploaded .docx on disk local (docx type, M5).
            $table->string('source_path')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->nullable();
            // chat_messages already exists (2026_03_04_071014). The message that
            // generated this template, mirroring reports.chat_message_id.
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_system', 'company_id']);
            $table->index(['user_id', 'company_id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
