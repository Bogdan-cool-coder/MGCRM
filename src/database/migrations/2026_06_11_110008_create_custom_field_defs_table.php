<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_defs', function (Blueprint $table): void {
            $table->id();

            // Scope: contact | company | deal (deal scope for S1.3)
            $table->string('entity_scope', 32);

            // Slug key — unique within scope. Must match [a-z0-9_]
            $table->string('code', 64);

            // Display
            $table->string('label', 255);
            $table->string('help_text', 512)->nullable();

            // Type: text | textarea | number | date | select | multiselect | boolean | url | user_ref
            $table->string('field_type', 32);

            // Options for select/multiselect: JSON array of strings
            $table->json('options')->default('[]');

            // Default value as JSON (null = no default)
            $table->json('default_value')->nullable();

            // Validation
            $table->boolean('required')->default(false);

            // UI grouping (section label)
            $table->string('group', 128)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Unique (scope, code) — prevents field key collisions per entity type
            $table->unique(['entity_scope', 'code']);
            $table->index('entity_scope');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_defs');
    }
};
