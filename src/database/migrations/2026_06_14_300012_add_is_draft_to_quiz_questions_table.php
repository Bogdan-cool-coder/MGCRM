<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3.5: Add is_draft flag to quiz_questions.
 *
 * is_draft=true marks AI-generated questions that require HR review before
 * being included in the published quiz. Existing questions default to false
 * (backward compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->boolean('is_draft')->notNull()->default(false)->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropColumn('is_draft');
        });
    }
};
