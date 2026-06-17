<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 64)->unique();
            // kind: 'docx' | 'yaml' | 'text'
            $table->string('kind', 16);
            $table->string('title', 255);
            // For yaml/text: the template content.
            // For docx: empty string; actual file path is in TemplateVersion.docx_path.
            $table->text('content')->default('');
            $table->integer('version')->default(1);

            // FK to template_versions.id — added in migration 200004 (circular FK workaround).
            // Declared here as unsignedBigInteger without constraint.
            $table->unsignedBigInteger('current_version_id')->nullable();

            // sublicense_main | addendum | notice | act | cancellation | null (yaml service)
            $table->string('category', 32)->nullable()->index();

            // Wildcard arrays: empty = matches all.
            $table->json('product_codes')->default('[]');
            $table->json('country_codes')->default('[]');
            $table->json('client_category_codes')->default('[]');
            $table->json('department_ids')->default('[]');

            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('kind', 'ix_templates_kind');
            $table->index('code', 'ix_templates_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
