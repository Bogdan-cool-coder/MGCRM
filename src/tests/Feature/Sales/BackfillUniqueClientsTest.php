<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N5 — sales:backfill-unique-clients retro-stamps is_primary_deal +
 * company.unique_client_since from existing won deals, deterministically and
 * idempotently.
 */
class BackfillUniqueClientsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** A won deal on $company with explicit fact dates (bypasses move() — raw seed). */
    private function wonDeal(User $user, Company $company, Pipeline $pipeline, ?string $signedAt, ?string $closedAt): Deal
    {
        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'won'),
            'company_id' => $company->id,
            'signed_at' => $signedAt,
            'closed_at' => $closedAt,
        ]);
    }

    public function test_backfill_picks_earliest_won_deal_as_primary(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $pipeline = $this->seedSalesPipeline();

        $later = $this->wonDeal($user, $company, $pipeline, '2026-08-01', '2026-08-02');
        $earliest = $this->wonDeal($user, $company, $pipeline, '2026-02-15', '2026-02-16');

        $this->artisan('sales:backfill-unique-clients')->assertExitCode(0);

        $earliest->refresh();
        $later->refresh();
        $company->refresh();

        $this->assertTrue($earliest->is_primary_deal);
        $this->assertFalse($later->is_primary_deal);
        $this->assertSame(ClientStatus::Active, $company->client_status);
        $this->assertSame('2026-02-15', $company->unique_client_since?->toDateString());
    }

    public function test_backfill_falls_back_to_closed_at_when_signed_at_null(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $pipeline = $this->seedSalesPipeline();

        // signed_at null → ordering uses closed_at; this is the earlier closed_at.
        $earlier = $this->wonDeal($user, $company, $pipeline, null, '2026-01-10');
        $later = $this->wonDeal($user, $company, $pipeline, null, '2026-06-20');

        $this->artisan('sales:backfill-unique-clients')->assertExitCode(0);

        $earlier->refresh();
        $later->refresh();
        $company->refresh();

        $this->assertTrue($earlier->is_primary_deal);
        $this->assertFalse($later->is_primary_deal);
        $this->assertSame('2026-01-10', $company->unique_client_since?->toDateString());
    }

    public function test_backfill_breaks_ties_by_smaller_id(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $pipeline = $this->seedSalesPipeline();

        // Identical dates → the smaller id wins (created first).
        $first = $this->wonDeal($user, $company, $pipeline, '2026-04-01', '2026-04-01');
        $second = $this->wonDeal($user, $company, $pipeline, '2026-04-01', '2026-04-01');

        $this->artisan('sales:backfill-unique-clients')->assertExitCode(0);

        $first->refresh();
        $second->refresh();

        $this->assertTrue($first->is_primary_deal);
        $this->assertFalse($second->is_primary_deal);
    }

    public function test_backfill_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $pipeline = $this->seedSalesPipeline();

        $earliest = $this->wonDeal($user, $company, $pipeline, '2026-03-03', '2026-03-04');
        $this->wonDeal($user, $company, $pipeline, '2026-07-07', '2026-07-08');

        $this->artisan('sales:backfill-unique-clients')->assertExitCode(0);
        $company->refresh();
        $firstDate = $company->unique_client_since?->toDateString();

        // Second run — same flags, same date (markAsUniqueClient is a no-op).
        $this->artisan('sales:backfill-unique-clients')->assertExitCode(0);

        $earliest->refresh();
        $company->refresh();

        $this->assertTrue($earliest->is_primary_deal);
        $this->assertSame($firstDate, $company->unique_client_since?->toDateString());
        $this->assertSame(1, Deal::where('company_id', $company->id)->where('is_primary_deal', true)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $pipeline = $this->seedSalesPipeline();

        $deal = $this->wonDeal($user, $company, $pipeline, '2026-05-05', '2026-05-06');

        $this->artisan('sales:backfill-unique-clients', ['--dry-run' => true])->assertExitCode(0);

        $deal->refresh();
        $company->refresh();

        $this->assertFalse($deal->is_primary_deal);
        $this->assertNull($company->unique_client_since);
    }
}
