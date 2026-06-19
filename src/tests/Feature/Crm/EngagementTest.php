<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for Engagement fields in API responses (B2).
 * Tests: last_activity_at and engagement_tier in Contact/Company resources.
 * Also tests the engagement_tier filter and sort by last_activity_at.
 */
class EngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_returns_engagement_fields(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => Carbon::now()->subDays(3),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.engagement_tier', 'fresh')
            ->assertJsonStructure(['data' => ['last_activity_at', 'engagement_tier', 'channels']]);
    }

    public function test_contact_null_activity_is_cold(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => null,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.engagement_tier', 'cold');
    }

    public function test_contact_50_days_ago_is_cold(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => Carbon::now()->subDays(50),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.engagement_tier', 'cold');
    }

    public function test_company_show_returns_engagement_fields(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'last_activity_at' => Carbon::now()->subDays(10),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.engagement_tier', 'fresh')
            ->assertJsonStructure(['data' => ['last_activity_at', 'engagement_tier', 'deal_totals']]);
    }

    public function test_contacts_filter_by_engagement_tier_cold(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Fresh Contact',
            'last_activity_at' => Carbon::now()->subDays(3),
        ]);
        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Cold Contact',
            'last_activity_at' => null,
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/contacts?engagement_tier=cold')
            ->assertOk();

        $names = collect($resp->json('data'))->pluck('full_name')->all();
        $this->assertContains('Cold Contact', $names);
        $this->assertNotContains('Fresh Contact', $names);
    }

    public function test_contacts_sort_by_last_activity_at(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Older',
            'last_activity_at' => Carbon::now()->subDays(20),
        ]);
        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Newer',
            'last_activity_at' => Carbon::now()->subDays(2),
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/contacts?sort=last_activity_at&direction=desc')
            ->assertOk();

        $names = collect($resp->json('data'))->pluck('full_name')->all();
        $this->assertSame('Newer', $names[0]);
    }
}
