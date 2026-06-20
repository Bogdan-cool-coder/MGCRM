<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ContactKpiTest — verifies the kpi block returned by GET /api/contacts/{id}.
 *
 * Fields:
 *   deals_count     — total deals this contact participates in (via deal_contacts)
 *   last_touch_at   — mirrors last_activity_at column
 *   open_tasks_count— open (not closed, not done) task-like activities targeting contact
 */
class ContactKpiTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers ----

    private function actingAsManager(): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function openTaskFor(Contact $contact, User $user): Activity
    {
        return Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Contact->value,
            'target_id' => $contact->id,
            'status' => ActivityStatus::New->value,
            'is_closed' => false,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);
    }

    private function closedTaskFor(Contact $contact, User $user): Activity
    {
        return Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Contact->value,
            'target_id' => $contact->id,
            'status' => ActivityStatus::Done->value,
            'is_closed' => true,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->subDay(),
            'completed_at' => now()->subHour(),
        ]);
    }

    // ---- deals_count ----

    public function test_kpi_deals_count_counts_deal_contacts(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $d1 = Deal::factory()->inStage($stage)->create();
        $d2 = Deal::factory()->inStage($stage)->create();

        DealContact::factory()->create(['deal_id' => $d1->id, 'contact_id' => $contact->id]);
        DealContact::factory()->create(['deal_id' => $d2->id, 'contact_id' => $contact->id]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.deals_count', 2);
    }

    public function test_kpi_deals_count_is_zero_when_no_deal_contacts(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.deals_count', 0);
    }

    public function test_kpi_deals_count_excludes_soft_deleted_deals(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $deal = Deal::factory()->inStage($stage)->create();
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        // Soft-delete the deal
        $deal->delete();

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.deals_count', 0);
    }

    // ---- last_touch_at ----

    public function test_kpi_last_touch_at_reflects_last_activity_at_column(): void
    {
        $user = $this->actingAsManager();
        $ts = now()->subDays(5)->startOfSecond();
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => $ts,
        ]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.last_touch_at', $ts->toIso8601String());
    }

    public function test_kpi_last_touch_at_is_null_when_never_touched(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => null,
        ]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.last_touch_at', null);
    }

    // ---- open_tasks_count ----

    public function test_kpi_open_tasks_count_counts_open_task_like_activities(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->openTaskFor($contact, $user);
        $this->openTaskFor($contact, $user);

        // Completed task — should be excluded
        $this->closedTaskFor($contact, $user);

        // Note (not task-like) — should also be excluded
        Activity::factory()->create([
            'kind' => ActivityType::Note->value,
            'target_type' => ActivityTargetType::Contact->value,
            'target_id' => $contact->id,
            'status' => ActivityStatus::New->value,
            'is_closed' => false,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => null,
        ]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', 2);
    }

    public function test_kpi_open_tasks_count_is_zero_when_no_tasks(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', 0);
    }

    public function test_kpi_open_tasks_count_excludes_done_activities(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        // Done task only
        $this->closedTaskFor($contact, $user);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', 0);
    }

    public function test_kpi_open_tasks_count_includes_all_task_like_kinds(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $taskLikeKinds = [
            ActivityType::Call->value,
            ActivityType::Meeting->value,
            ActivityType::Task->value,
            ActivityType::FollowUp->value,
        ];

        foreach ($taskLikeKinds as $kind) {
            Activity::factory()->create([
                'kind' => $kind,
                'target_type' => ActivityTargetType::Contact->value,
                'target_id' => $contact->id,
                'status' => ActivityStatus::New->value,
                'is_closed' => false,
                'responsible_id' => $user->id,
                'created_by_id' => $user->id,
                'due_at' => now()->addDay(),
            ]);
        }

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', count($taskLikeKinds));
    }

    // ---- kpi block structure ----

    public function test_show_response_contains_kpi_block_with_all_keys(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'kpi' => [
                        'deals_count',
                        'last_touch_at',
                        'open_tasks_count',
                    ],
                ],
            ]);
    }

    // ---- index does NOT expose kpi (no N+1 on list) ----

    public function test_index_does_not_expose_kpi_block(): void
    {
        $user = $this->actingAsManager();
        Contact::factory()->count(2)->create(['owner_id' => $user->id]);

        $response = $this->getJson('/api/contacts')->assertOk();

        foreach ($response->json('data') as $item) {
            $this->assertNull($item['kpi']);
        }
    }
}
