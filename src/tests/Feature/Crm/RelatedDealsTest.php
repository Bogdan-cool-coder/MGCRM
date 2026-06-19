<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for contact/company deals sub-resource endpoints (B4).
 * Verifies that stubs have been replaced with real data.
 * Uses PipelineSeeder so the test pipeline has a proper stage.
 */
class RelatedDealsTest extends TestCase
{
    use RefreshDatabase;

    private function seedPipeline(): array
    {
        $this->seed(PipelineSeeder::class);
        $pipeline = Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
        $stage = $pipeline->stages->first();

        return [$pipeline, $stage];
    }

    public function test_contact_deals_returns_real_deals(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        [$pipeline, $stage] = $this->seedPipeline();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'owner_user_id' => $user->id,
            'department_id' => null,
            'amount' => 100_000,
            'currency' => 'RUB',
        ]);

        DealContact::create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/contacts/{$contact->id}/deals")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $dealIds = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($deal->id, $dealIds);
    }

    public function test_company_deals_returns_real_deals(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        [$pipeline, $stage] = $this->seedPipeline();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'owner_user_id' => $user->id,
            'department_id' => null,
            'amount' => 50_000,
            'currency' => 'KZT',
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/companies/{$company->id}/deals")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $dealIds = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($deal->id, $dealIds);
    }

    public function test_contact_deals_empty_for_no_deals(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/contacts/{$contact->id}/deals")
            ->assertOk();

        $this->assertCount(0, $resp->json('data'));
    }

    public function test_company_show_includes_deal_totals_structure(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        // No deals — totals should still be present with zero open_count
        $resp = $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'deal_totals' => ['per_currency', 'base_currency', 'open_count', 'as_of_date'],
            ]]);

        $totals = $resp->json('data.deal_totals');
        $this->assertSame(0, $totals['open_count']);
        $this->assertIsArray($totals['per_currency']);
    }
}
