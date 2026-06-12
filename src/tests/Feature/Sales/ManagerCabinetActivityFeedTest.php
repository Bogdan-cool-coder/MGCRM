<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/me/activity-feed (S1.8).
 */
class ManagerCabinetActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'is_active' => true,
        ]);
    }

    /**
     * Build a full FTM-qualified meeting activity.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function ftmActivity(User $manager, array $overrides = []): Activity
    {
        return Activity::factory()->meeting()->responsibleOf($manager)->create(array_merge([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com/report/1',
            'created_at' => now()->startOfMonth()->addHours(2),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/me/activity-feed')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Scoped to responsible_id
    // -------------------------------------------------------------------------

    public function test_feed_scoped_to_responsible_id(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();

        // 2 activities for manager, 1 for other
        Activity::factory()->responsibleOf($manager)->count(2)->create([
            'created_at' => now(),
        ]);
        Activity::factory()->responsibleOf($other)->create(['created_at' => now()]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();

        $response->assertJsonPath('meta.total', 2);
    }

    // -------------------------------------------------------------------------
    // Kind filter
    // -------------------------------------------------------------------------

    public function test_feed_kind_filter_meeting(): void
    {
        $manager = $this->makeManager();

        Activity::factory()->meeting()->responsibleOf($manager)->create(['created_at' => now()]);
        Activity::factory()->call()->responsibleOf($manager)->create(['created_at' => now()]);
        Activity::factory()->task()->responsibleOf($manager)->create(['created_at' => now()]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month&kind=meeting')
            ->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('meeting', $data[0]['kind']);
    }

    public function test_feed_kind_all_returns_all_kinds(): void
    {
        $manager = $this->makeManager();

        Activity::factory()->meeting()->responsibleOf($manager)->create(['created_at' => now()]);
        Activity::factory()->call()->responsibleOf($manager)->create(['created_at' => now()]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month&kind=all')
            ->assertOk();

        $response->assertJsonPath('meta.total', 2);
    }

    // -------------------------------------------------------------------------
    // FTM filters
    // -------------------------------------------------------------------------

    public function test_feed_ftm_only_returns_only_counted_ftm(): void
    {
        $manager = $this->makeManager();

        // 2 FTM
        $this->ftmActivity($manager);
        $this->ftmActivity($manager);

        // 1 non-FTM meeting (missing decision maker)
        Activity::factory()->meeting()->responsibleOf($manager)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month&ftm_only=1')
            ->assertOk();

        $response->assertJsonPath('meta.total', 2);
        foreach ($response->json('data') as $item) {
            $this->assertTrue($item['ftm_counted']);
        }
    }

    public function test_feed_ftm_only_accepts_string_true_from_axios(): void
    {
        $manager = $this->makeManager();

        // 1 FTM, 1 non-FTM meeting
        $this->ftmActivity($manager);
        Activity::factory()->meeting()->responsibleOf($manager)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($manager, ['*']);

        // axios serializes JS boolean true to the string "true" in the query string.
        $this->getJson('/api/me/activity-feed?period=current_month&ftm_only=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_feed_ftm_only_accepts_string_false_from_axios(): void
    {
        $manager = $this->makeManager();

        $this->ftmActivity($manager);
        Activity::factory()->call()->responsibleOf($manager)->create(['created_at' => now()]);

        Sanctum::actingAs($manager, ['*']);

        // "false" must not be rejected by the boolean rule and must not filter anything.
        $this->getJson('/api/me/activity-feed?period=current_month&ftm_only=false')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_feed_ftm_counted_flag_true_when_all_conditions_met(): void
    {
        $manager = $this->makeManager();
        $this->ftmActivity($manager);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['ftm_counted']);
    }

    public function test_feed_ftm_counted_flag_false_when_conditions_not_met(): void
    {
        $manager = $this->makeManager();

        // Meeting but no ftm_report_url
        Activity::factory()->meeting()->responsibleOf($manager)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertFalse($data[0]['ftm_counted']);
    }

    // -------------------------------------------------------------------------
    // Period filter
    // -------------------------------------------------------------------------

    public function test_feed_period_filter_restricts_by_created_at(): void
    {
        $manager = $this->makeManager();

        // Current month activity
        Activity::factory()->responsibleOf($manager)->create([
            'created_at' => now()->startOfMonth()->addDay(),
        ]);

        // Last month activity — must NOT appear
        Activity::factory()->responsibleOf($manager)->create([
            'created_at' => now()->startOfMonth()->subMonth()->midDay(),
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    public function test_feed_paginated_25_per_page_default(): void
    {
        $manager = $this->makeManager();

        // Create 30 activities in this month
        Activity::factory()->responsibleOf($manager)->count(30)->create([
            'created_at' => now()->startOfMonth()->addHour(),
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();

        $response->assertJsonPath('meta.per_page', 25);
        $response->assertJsonPath('meta.total', 30);
        $this->assertCount(25, $response->json('data'));
    }

    // -------------------------------------------------------------------------
    // Response structure
    // -------------------------------------------------------------------------

    public function test_feed_item_has_required_fields(): void
    {
        $manager = $this->makeManager();
        Activity::factory()->meeting()->responsibleOf($manager)->create(['created_at' => now()]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/activity-feed?period=current_month')->assertOk();
        $item = $response->json('data.0');

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('kind', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('ftm_counted', $item);
        $this->assertArrayHasKey('is_first_time_meeting', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    // -------------------------------------------------------------------------
    // Visibility scope
    // -------------------------------------------------------------------------

    public function test_feed_manager_cannot_view_other_user_feed(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/activity-feed?user_id='.$other->id)
            ->assertForbidden();
    }

    public function test_feed_director_can_view_any_user_feed(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = $this->makeManager();

        Activity::factory()->responsibleOf($manager)->create(['created_at' => now()]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/me/activity-feed?user_id='.$manager->id.'&period=current_month')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
