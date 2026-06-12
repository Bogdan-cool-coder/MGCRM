<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_variables', function (Blueprint $table): void {
            $table->id();

            // Unique key used as {{ custom.<key> }} in templates.
            $table->string('key', 64)->unique();
            $table->string('label', 255);
            $table->string('help_text', 512)->nullable();

            // text | textarea | number | date | select | checkbox
            $table->string('var_type', 16)->default('text');

            // For select type: list of option strings.
            $table->json('options')->default('[]');
            $table->string('default_value', 512)->nullable();

            $table->boolean('required')->default(false);
            $table->string('group', 128)->nullable();
            $table->integer('sort_order')->default(0);

            // Wildcard arrays: empty = matches all products/countries.
            $table->json('product_codes')->default('[]');
            $table->json('country_codes')->default('[]');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('key', 'ix_template_variables_key');
            $table->index(['is_active', 'sort_order'], 'ix_template_variables_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_variables');
    }
};
