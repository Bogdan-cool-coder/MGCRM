<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->string('kind', 16)->default('sales');   // PipelineKind enum
            // SQLite has no native jsonb; json is portable for tests.
            $table->json('settings')->default('{}');
            $table->string('visible_role', 32)->nullable();
            $table->json('visible_user_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['kind', 'is_active'], 'ix_pipelines_kind_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
