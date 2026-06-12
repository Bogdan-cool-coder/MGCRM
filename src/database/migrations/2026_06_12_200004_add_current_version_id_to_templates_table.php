<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the circular FK between templates and template_versions.
 *
 * templates.current_version_id → template_versions.id
 *
 * Cannot be declared in the templates migration (200002) because template_versions
 * does not exist yet. Added here after template_versions is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'fk_templates_current_version')
                ->references('id')
                ->on('template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropForeign('fk_templates_current_version');
        });
    }
};
