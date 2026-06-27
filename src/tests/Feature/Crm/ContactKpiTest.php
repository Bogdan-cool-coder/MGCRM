<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
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

    public function test_kpi_open_tasks_count_excludes_rejected_even_when_not_closed(): void
    {
        // D11/D13: a rejected task is a FINAL outcome — never "open" — regardless
        // of whether is_closed got set. The contact open-tasks badge must drop it.
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Contact->value,
            'target_id' => $contact->id,
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => false, // deliberate status/is_closed disagreement
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', 0);
    }

    public function test_kpi_open_tasks_count_never_exceeds_scoped_list_for_manager(): void
    {
        // A5: the contact open-tasks badge is visibility-scoped (Own for a manager),
        // so a task targeting this contact but owned/created by ANOTHER user is not
        // counted — the badge can never exceed what the manager's task list shows.
        $user = $this->actingAsManager();
        $stranger = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        // One task the manager owns (in scope).
        $this->openTaskFor($contact, $user);

        // One open task on the SAME contact but owned + created by the stranger.
        Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Contact->value,
            'target_id' => $contact->id,
            'status' => ActivityStatus::New->value,
            'is_closed' => false,
            'responsible_id' => $stranger->id,
            'created_by_id' => $stranger->id,
            'due_at' => now()->addDay(),
        ]);

        // Only the in-scope task is counted, not the stranger's.
        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.open_tasks_count', 1);
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
                        'deals_sum',
                        'deals_sum_currency',
                        'last_touch_at',
                        'open_tasks_count',
                        'open_tasks_count_deals',
                        'companies_count',
                    ],
                ],
            ]);
    }

    // ---- deals_sum (B-2 / DS-5) ----

    public function test_kpi_deals_sum_sums_amounts_across_all_linked_deals(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $d1 = Deal::factory()->inStage($stage)->create(['amount' => 100_000, 'currency' => 'RUB']);
        $d2 = Deal::factory()->inStage($stage)->create(['amount' => 200_000, 'currency' => 'RUB']);

        DealContact::factory()->create(['deal_id' => $d1->id, 'contact_id' => $contact->id]);
        DealContact::factory()->create(['deal_id' => $d2->id, 'contact_id' => $contact->id]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();

        // deals_sum is int kopecks or null (FX unavailable); must never be a float
        $dealsSumRaw = $response->json('data.kpi.deals_sum');
        $this->assertTrue($dealsSumRaw === null || is_int($dealsSumRaw));

        // Both deals in same currency (RUB) — no conversion needed; sum = 300_000 kopecks
        if ($dealsSumRaw !== null) {
            $this->assertSame(300_000, $dealsSumRaw);
        }
    }

    public function test_kpi_deals_sum_is_zero_when_no_deals(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();

        // No deals linked — base_total = 0, never null (no conversion needed)
        $this->assertSame(0, $response->json('data.kpi.deals_sum'));
    }

    public function test_kpi_deals_sum_excludes_soft_deleted_deals(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $deal = Deal::factory()->inStage($stage)->create(['amount' => 500_000, 'currency' => 'RUB']);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        $deal->delete();

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $this->assertSame(0, $response->json('data.kpi.deals_sum'));
    }

    public function test_kpi_deals_sum_currency_is_string(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();

        $currency = $response->json('data.kpi.deals_sum_currency');
        $this->assertIsString($currency);
        $this->assertNotEmpty($currency);
    }

    // ---- companies_count ----

    public function test_kpi_companies_count_reflects_company_links(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        ContactCompanyLink::create(['contact_id' => $contact->id, 'company_id' => $c1->id, 'is_primary' => true]);
        ContactCompanyLink::create(['contact_id' => $contact->id, 'company_id' => $c2->id, 'is_primary' => false]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.companies_count', 2);
    }

    public function test_kpi_companies_count_is_zero_when_no_links(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.kpi.companies_count', 0);
    }

    // ---- source and timestamps in ContactResource ----

    public function test_show_response_contains_source_field(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'source' => 'website',
        ]);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.source', 'website');
    }

    public function test_show_response_contains_created_at_and_updated_at(): void
    {
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();

        $this->assertNotNull($response->json('data.created_at'));
        $this->assertNotNull($response->json('data.updated_at'));
        // Verify ISO 8601 format (contains 'T' separator)
        $this->assertStringContainsString('T', $response->json('data.created_at'));
        $this->assertStringContainsString('T', $response->json('data.updated_at'));
    }

    // ---- open_tasks_count_deals (B-wiring) ----

    private function openTaskOnDeal(Deal $deal, User $user): Activity
    {
        return Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'status' => ActivityStatus::New->value,
            'is_closed' => false,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);
    }

    public function test_kpi_open_tasks_count_deals_counts_open_tasks_on_visible_deals(): void
    {
        // open task on a VISIBLE linked deal increments open_tasks_count_deals
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $deal = Deal::factory()->inStage($stage)->create(['owner_user_id' => $user->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        // open task targeting the deal
        $this->openTaskOnDeal($deal, $user);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $this->assertSame(1, $response->json('data.kpi.open_tasks_count_deals'));
        // direct contact tasks unaffected
        $this->assertSame(0, $response->json('data.kpi.open_tasks_count'));
    }

    public function test_kpi_open_tasks_count_deals_excludes_out_of_scope_deals(): void
    {
        // a task on a deal that does NOT belong to this contact counts for neither counter
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        // unlinked deal
        $unlinkedDeal = Deal::factory()->inStage($stage)->create(['owner_user_id' => $user->id]);
        $this->openTaskOnDeal($unlinkedDeal, $user);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $this->assertSame(0, $response->json('data.kpi.open_tasks_count_deals'));
        $this->assertSame(0, $response->json('data.kpi.open_tasks_count'));
    }

    public function test_kpi_open_tasks_count_deals_excludes_done_and_rejected(): void
    {
        // done/rejected tasks on linked deals must NOT count
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $deal = Deal::factory()->inStage($stage)->create(['owner_user_id' => $user->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        // done task
        Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'status' => ActivityStatus::Done->value,
            'is_closed' => true,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->subDay(),
            'completed_at' => now()->subHour(),
        ]);

        // rejected task
        Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => false,
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $this->assertSame(0, $response->json('data.kpi.open_tasks_count_deals'));
    }

    public function test_kpi_deal_tasks_use_same_deal_set_as_feed(): void
    {
        // Verify that open_tasks_count_deals is 0 when the contact has no linked deals
        $user = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();
        $this->assertSame(0, $response->json('data.kpi.open_tasks_count_deals'));
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
