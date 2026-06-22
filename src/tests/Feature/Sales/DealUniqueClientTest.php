<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\DealMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N5 (Фича 3) — unique-client detect on the won-transition. Exercised through
 * DealMoveService::move() directly (no HTTP layer needed for the lifecycle logic).
 */
class DealUniqueClientTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function wonStageId(Pipeline $pipeline): int
    {
        return $this->stageCode($pipeline, 'won');
    }

    private function dealAtHot(User $user, Company $company, array $attrs = []): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($user)->create(array_merge([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'hot'),
            'company_id' => $company->id,
        ], $attrs));
    }

    /** A live contract so the won-gate (S2.8) never blocks the lifecycle assertions. */
    private function approvedContractFor(Deal $deal): void
    {
        Document::factory()->approved()->create([
            'source_deal_id' => $deal->id,
            'author_user_id' => $deal->owner_user_id,
        ]);
    }

    public function test_first_won_marks_company_unique_client_and_flags_primary(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $deal = $this->dealAtHot($user, $company, ['signed_at' => '2026-05-10']);
        $this->approvedContractFor($deal);

        $won = $this->wonStageId($deal->pipeline);
        app(DealMoveService::class)->move($deal, $won, $user->id);

        $deal->refresh();
        $company->refresh();

        $this->assertTrue($deal->is_primary_deal);
        $this->assertSame(ClientStatus::Active, $company->client_status);
        $this->assertSame('2026-05-10', $company->unique_client_since?->toDateString());
    }

    public function test_second_won_on_same_company_is_upsell_and_keeps_first_date(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        // First won deal converts the company (signed 2026-03-01).
        $first = $this->dealAtHot($user, $company, ['signed_at' => '2026-03-01']);
        $this->approvedContractFor($first);
        app(DealMoveService::class)->move($first, $this->wonStageId($first->pipeline), $user->id);

        $company->refresh();
        $this->assertSame('2026-03-01', $company->unique_client_since?->toDateString());

        // Second won deal on the SAME company, later date → upsell, not primary.
        $second = $this->dealAtHot($user, $company, ['signed_at' => '2026-09-09']);
        $this->approvedContractFor($second);
        app(DealMoveService::class)->move($second, $this->wonStageId($second->pipeline), $user->id);

        $second->refresh();
        $company->refresh();

        $this->assertFalse($second->is_primary_deal);
        // Idempotent: the conversion date is unchanged by the upsell.
        $this->assertSame('2026-03-01', $company->unique_client_since?->toDateString());
    }

    public function test_won_without_signed_at_uses_closed_at_date(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        // No signed_at → the detect falls back to closed_at (stamped by move()).
        $deal = $this->dealAtHot($user, $company, ['signed_at' => null]);
        $this->approvedContractFor($deal);

        app(DealMoveService::class)->move($deal, $this->wonStageId($deal->pipeline), $user->id);

        $deal->refresh();
        $company->refresh();

        $this->assertTrue($deal->is_primary_deal);
        $this->assertNotNull($company->unique_client_since);
        // closed_at is stamped to now() on the won move, so the client date matches.
        $this->assertSame(
            $deal->closed_at->toDateString(),
            $company->unique_client_since->toDateString(),
        );
    }
}
