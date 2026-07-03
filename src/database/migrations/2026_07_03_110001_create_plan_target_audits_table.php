<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_target_audits', function (Blueprint $table): void {
            $table->id();
            // nullOnDelete (NOT cascade): the audit log is the permanent record of
            // what happened to a cell, including its own deletion (contract §2.2 /
            // §O10 "clearing a grid input ... logged as change-to-null"). A cascade
            // FK would wipe the just-written "deleted" audit row the instant the
            // cell itself is deleted, destroying the very history this table exists
            // to keep.
            $table->foreignId('plan_target_id')->nullable()->constrained('plan_targets')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field', 24); // value_kopecks | value_count | currency | config
            $table->string('old_value')->nullable(); // JSON-encoded scalar
            $table->string('new_value')->nullable(); // JSON-encoded scalar
            $table->timestampTz('created_at')->nullable();

            $table->index(['plan_target_id', 'created_at'], 'ix_plan_target_audits_target_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_target_audits');
    }
};
