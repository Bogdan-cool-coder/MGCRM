<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingReportTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_save_report_creates_meeting_activity(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        $question = MeetingReportQuestion::factory()->global()->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'answers' => [
                ['question_id' => $question->id, 'text' => $question->text, 'answer' => 'Да'],
            ],
            'comment' => 'Прошла отлично',
        ])->assertSuccessful()
            ->assertJsonPath('data.kind', 'meeting')
            ->assertJsonPath('data.target_type', 'deal')
            ->assertJsonPath('data.target_id', $deal->id);

        $this->assertDatabaseHas('activities', [
            'kind' => 'meeting',
            'target_type' => 'deal',
            'target_id' => $deal->id,
        ]);
    }

    public function test_save_report_updates_existing_activity(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        $question = MeetingReportQuestion::factory()->global()->create();
        $existing = Activity::factory()->meeting()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'activity_id' => $existing->id,
            'answers' => [
                ['question_id' => $question->id, 'text' => $question->text, 'answer' => 'Нет'],
            ],
            'comment' => 'Обновлённый отчёт',
        ])->assertSuccessful()
            ->assertJsonPath('data.id', $existing->id);

        $this->assertSame('Нет', $existing->fresh()->meeting_report_json['answers'][0]['answer']);
    }

    public function test_save_report_rejects_unknown_question_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        MeetingReportQuestion::factory()->global()->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'answers' => [
                ['question_id' => 99999, 'answer' => 'x'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('answers');
    }

    public function test_save_report_rejects_empty(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'answers' => [],
            'comment' => '',
        ])->assertStatus(422)->assertJsonValidationErrorFor('comment');
    }

    public function test_questions_endpoint_returns_global_and_pipeline_questions(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $otherPipeline = Pipeline::factory()->create();
        $manager = $this->manager();
        $global = MeetingReportQuestion::factory()->global()->create();
        $pipelineQ = MeetingReportQuestion::factory()->forPipeline($pipeline->id)->create();
        $otherPipelineQ = MeetingReportQuestion::factory()->forPipeline($otherPipeline->id)->create();
        Sanctum::actingAs($manager, ['*']);

        $ids = collect($this->getJson("/api/meeting-report/questions?pipeline_id={$pipeline->id}")
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($global->id, $ids);
        $this->assertContains($pipelineQ->id, $ids);
        $this->assertNotContains($otherPipelineQ->id, $ids);
    }

    public function test_meeting_question_admin_crud_role_gate(): void
    {
        $this->seedSalesPipeline();
        $manager = $this->manager();
        Sanctum::actingAs($manager, ['*']);

        // Manager cannot create registry questions.
        $this->postJson('/api/meeting-report-questions', [
            'text' => 'New question?',
            'kind' => 'text',
        ])->assertForbidden();
    }

    public function test_director_can_create_meeting_question_with_options(): void
    {
        $this->seedSalesPipeline();
        $director = $this->director();
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/meeting-report-questions', [
            'text' => 'Decision maker present?',
            'kind' => 'select',
            'options' => [
                ['text' => 'Yes'],
                ['text' => 'No'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'select')
            ->assertJsonCount(2, 'data.options');
    }

    public function test_director_can_create_required_question_and_resource_exposes_flag(): void
    {
        // Audit MINOR-6: is_required is now a real backing column, not phantom FE.
        $this->seedSalesPipeline();
        $director = $this->director();
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/meeting-report-questions', [
            'text' => 'Mandatory?',
            'kind' => 'text',
            'is_required' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_required', true);
    }

    public function test_questions_endpoint_exposes_is_required(): void
    {
        $this->seedSalesPipeline();
        $manager = $this->manager();
        MeetingReportQuestion::factory()->global()->create(['is_required' => true]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/meeting-report/questions')
            ->assertOk()
            ->assertJsonPath('data.0.is_required', true);
    }
}
