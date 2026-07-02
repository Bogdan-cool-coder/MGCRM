<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for ActivityService::purgeAll() — the `tasks` category cleaner
 * for the selective system-reset feature
 * (docs/contracts/system-reset-api-contract.md §1 row 4).
 *
 * `activities` is self-contained (polymorphic target, no FK — see
 * create_activities_table), so there is no child table ordering to assert;
 * coverage focuses on complete deletion, the returned count shape, and that
 * unrelated tables (pipelines/deals/companies/users/visibility_settings)
 * survive.
 */
class ActivityServicePurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_all_deletes_every_activity_kind_and_target(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'owner_user_id' => $user->id,
        ]);

        Activity::factory()->standalone()->responsibleOf($user)->create();
        Activity::factory()->forDeal($deal)->create();
        Activity::factory()->forCompany($company)->create();
        Activity::factory()->note()->forDeal($deal)->create();
        Activity::factory()->completed($user)->forDeal($deal)->create();

        $this->assertSame(5, DB::table('activities')->count());

        app(ActivityService::class)->purgeAll();

        $this->assertSame(0, DB::table('activities')->count());
    }

    public function test_purge_all_returns_pre_delete_count(): void
    {
        Activity::factory()->count(4)->standalone()->create();

        $counts = app(ActivityService::class)->purgeAll();

        $this->assertSame(['activities' => 4], $counts);
    }

    public function test_purge_all_returns_zero_when_no_activities_exist(): void
    {
        $counts = app(ActivityService::class)->purgeAll();

        $this->assertSame(['activities' => 0], $counts);
    }

    public function test_purge_all_never_touches_deals_or_pipelines(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => User::factory()->create()->id,
        ]);

        Activity::factory()->forDeal($deal)->create();

        app(ActivityService::class)->purgeAll();

        $this->assertDatabaseHas('deals', ['id' => $deal->id]);
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $stage->id]);
    }

    public function test_purge_all_never_touches_users_or_companies(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        Activity::factory()->responsibleOf($user)->forCompany($company)->create();

        app(ActivityService::class)->purgeAll();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('crm_companies', ['id' => $company->id]);
    }

    public function test_purge_all_never_touches_visibility_settings(): void
    {
        DB::table('visibility_settings')->insert([
            'role' => 'manager',
            'scope' => 'own',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Activity::factory()->count(2)->standalone()->create();

        app(ActivityService::class)->purgeAll();

        $this->assertSame(1, DB::table('visibility_settings')->count());
        $this->assertDatabaseHas('visibility_settings', ['role' => 'manager', 'scope' => 'own']);
    }
}
