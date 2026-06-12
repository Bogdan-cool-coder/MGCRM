<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_versions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('template_id')
                ->constrained('templates')
                ->cascadeOnDelete();

            $table->integer('version_number');

            // Path on disk 'documents' — null for yaml-kind templates.
            $table->string('docx_path', 512)->nullable();

            // AI review fields (filled in S2.3; null until then).
            // Schema: [{type: 'grammar'|'structure'|'placeholder', text: '...', severity: 'warning'|'info'}]
            $table->json('ai_remarks')->nullable();
            $table->boolean('ai_overridden')->default(false);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Immutable snapshot — no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['template_id', 'version_number'], 'uq_template_versions_template_version');
            $table->index('template_id', 'ix_template_versions_template');
            $table->index(['template_id', 'version_number'], 'ix_template_versions_template_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_versions');
    }
};
