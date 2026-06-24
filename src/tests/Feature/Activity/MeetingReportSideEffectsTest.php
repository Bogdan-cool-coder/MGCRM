<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The meeting-report constructor (E8) must behave like a completed meeting:
 *  - capture the four FTM flags (the canonical FTM-capture surface);
 *  - stamp engagement (last_activity_at) on the deal's company + linked contacts;
 *  - record a meeting_held entity-log on the deal.
 * Audit MAJOR-1 (FTM uncapturable) and MAJOR-4 (no engagement/log on save).
 */
class MeetingReportSideEffectsTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_save_report_captures_ftm_flags(): void
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
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com/report',
        ])->assertSuccessful()
            ->assertJsonPath('data.is_first_time_meeting', true)
            ->assertJsonPath('data.ftm_decision_maker_attended', true)
            ->assertJsonPath('data.ftm_presentation_shown', true)
            ->assertJsonPath('data.ftm_report_url', 'https://example.com/report');

        $this->assertDatabaseHas('activities', [
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com/report',
        ]);
    }

    public function test_save_report_without_ftm_block_defaults_flags_false(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'comment' => 'Без FTM',
        ])->assertSuccessful()
            ->assertJsonPath('data.is_first_time_meeting', false)
            ->assertJsonPath('data.ftm_report_url', null);
    }

    public function test_save_report_stamps_engagement_on_company_and_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);

        $linked = Contact::factory()->create(['owner_id' => $manager->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $linked->id]);

        // Isolate the save() touch — clear any pre-existing stamps.
        Company::query()->whereKey($deal->company_id)->update(['last_activity_at' => null]);
        Contact::query()->whereKey($linked->id)->update(['last_activity_at' => null]);

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'comment' => 'Встреча прошла',
        ])->assertSuccessful();

        $this->assertNotNull($deal->company->fresh()->last_activity_at, 'company touched');
        $this->assertNotNull($linked->fresh()->last_activity_at, 'linked contact touched');
    }

    public function test_save_report_writes_meeting_held_entity_log(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'comment' => 'Отчёт',
        ])->assertSuccessful();

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'meeting_held',
        ]);
    }

    public function test_save_report_update_preserves_prior_ftm_when_block_absent(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        // Create with FTM set.
        $created = $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'comment' => 'V1',
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com/r',
        ])->assertSuccessful()->json('data.id');

        // Update WITHOUT the FTM block — flags must survive.
        $this->postJson("/api/deals/{$deal->id}/meeting-report", [
            'activity_id' => $created,
            'comment' => 'V2',
        ])->assertSuccessful()
            ->assertJsonPath('data.is_first_time_meeting', true)
            ->assertJsonPath('data.ftm_report_url', 'https://example.com/r');
    }
}
