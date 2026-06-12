<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();

            // ----- Kind (ActivityType: call|meeting|task|note) -----
            $table->string('kind', 16);

            // ----- Polymorphic target WITHOUT FK (mirrors CrmFile) -----
            // 'deal' | 'company' | NULL (standalone personal task). Extending the
            // whitelist (contract/subscription) needs no migration.
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // ----- Core -----
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // ----- Actors -----
            $table->foreignId('responsible_id') // assignee
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('created_by_id') // orderer (from CurrentUser)
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // ----- Task v2 (MVP) -----
            $table->string('priority', 16)->default('normal');   // low|normal|high|critical
            $table->string('status', 16)->default('new');        // new|in_progress|done|rejected
            $table->boolean('is_closed')->default(false);
            $table->unsignedTinyInteger('progress_pct')->default(0); // 0..100 (checked in FormRequest)
            $table->text('result_text')->nullable();
            $table->boolean('is_pinned')->default(false);

            // ----- FTM (First Time Meeting) — data only, no automation in S1.6 -----
            $table->boolean('is_first_time_meeting')->default(false);
            $table->boolean('ftm_decision_maker_attended')->default(false);
            $table->boolean('ftm_presentation_shown')->default(false);
            $table->text('ftm_report_url')->nullable();

            // ----- MeetingReport answers snapshot -----
            // {answers:[{question_id,text,answer}], comment}
            $table->json('meeting_report_json')->nullable();

            // ----- Scope (denormalised from target for visibility/perf, like Deal) -----
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->timestamps();

            // Timeline by entity (E7) + sort.
            $table->index(['target_type', 'target_id'], 'ix_activities_target');
            $table->index(['target_type', 'target_id', 'due_at'], 'ix_activities_target_due');
            // "My tasks" / open-count.
            $table->index(['responsible_id', 'is_closed'], 'ix_activities_responsible');
            // "My orders".
            $table->index(['created_by_id', 'is_closed'], 'ix_activities_creator');
            // overdue / today / this_week.
            $table->index(['due_at', 'is_closed'], 'ix_activities_due_open');
            // department-scope.
            $table->index('department_id', 'ix_activities_department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
