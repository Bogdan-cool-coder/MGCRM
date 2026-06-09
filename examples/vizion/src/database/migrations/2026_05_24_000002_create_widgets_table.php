<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widgets — a standalone, reusable entity (small aggregating table + chart
 * presentation). Mirrors the `reports` visibility model (system / published /
 * personal) and AI provenance (chat_message_id, metadata dry-run flags).
 *
 * `chart_type` is intentionally NOT a column: the chart type lives only inside
 * `config.chart.type` (single source of truth — decision O5). `config` carries
 * primary_model, where[], group_by.fields[], aggregates[], chart{...} and an
 * optional period_field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null user_id => system widget (no author).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->jsonb('config');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_published')->default(false);
            // chat_messages already exists (2026_03_04_071014). The message that
            // generated this widget, mirroring reports.chat_message_id.
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_system', 'company_id']);
            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
