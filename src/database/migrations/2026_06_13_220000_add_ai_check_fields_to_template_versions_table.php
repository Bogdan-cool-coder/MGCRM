<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_versions', function (Blueprint $table): void {
            // Enum-backed string: pending / checking / checked / failed.
            // Default 'pending' — every new version starts awaiting AI review.
            $table->string('ai_check_status', 16)->default('pending')->after('ai_overridden');

            // Timestamp set when the job finishes (checked or failed).
            $table->timestamp('ai_checked_at')->nullable()->after('ai_check_status');

            // True when Gotenberg converted the docx successfully during S2.3 test.
            // Null means the check hasn't run yet.
            $table->boolean('pdf_ok')->nullable()->after('ai_checked_at');

            // Index for filtering «all versions pending review» in UI.
            $table->index('ai_check_status', 'ix_template_versions_ai_status');
        });
    }

    public function down(): void
    {
        Schema::table('template_versions', function (Blueprint $table): void {
            $table->dropIndex('ix_template_versions_ai_status');
            $table->dropColumn(['ai_check_status', 'ai_checked_at', 'pdf_ok']);
        });
    }
};
