<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_required for the meeting-report question registry. The frontend constructor
 * already renders a required marker (a red "*") and the DTO declares the field;
 * this gives it a real backing column so admins can mark a question mandatory and
 * the field stops being phantom FE-only contract (audit MINOR-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_report_questions', function (Blueprint $table): void {
            $table->boolean('is_required')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_report_questions', function (Blueprint $table): void {
            $table->dropColumn('is_required');
        });
    }
};
