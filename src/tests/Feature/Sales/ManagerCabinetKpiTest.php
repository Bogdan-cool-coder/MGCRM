<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\SalaryPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/me/kpi (S1.8).
 * Verifies auth, visibility scope, KPI structure contract, score_pct, FTM,
 * team comparison, and graceful zeros.
 */
class ManagerCabinetKpiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeManager(?int $deptId = null): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
    }

    private function makeDirector(?int $deptId = null): User
    {
        return User::factory()->create([
            'role' => Role::Director,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role' => Role::Admin,
            'is_active' => true,
        ]);
    }

    private function makeDept(): Department
    {
        return Department::factory()->create();
    }

    private function wonStage(): PipelineStage
    {
        $pipeline = Pipeline::factory()->create(['is_active' => true]);

        return PipelineStage::factory()->won()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 99,
        ]);
    }

    private function seedRate(string $from, string $to, float $rate): void
    {
        ExchangeRate::create([
            'from_code' => strtoupper($from),
            'to_code' => strtoupper($to),
            'rate' => $rate,
            'date' => now()->toDateString(),
        ]);
    }

    private function wonDeal(User $owner, PipelineStage $wonStage, int $amount, ?string $stageChangedAt = null): Deal
    {
        return Deal::factory()->create([
            'owner_user_id' => $owner->id,
            'stage_id' => $wonStage->id,
            'pipeline_id' => $wonStage->pipeline_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'stage_changed_at' => $stageChangedAt ?? now()->startOfMonth()->addDays(3),
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/me/kpi')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Response structure
    // -------------------------------------------------------------------------

    public function test_kpi_response_has_required_keys(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi')
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['user', 'period', 'base_currency', 'income_source', 'multi_currency_warning'],
                'personal' => [
                    'income_fact_kopecks',
                    'income_plan_kopecks',
                    'score_pct',
                    'score_badge',
                    'ftm_count_fact',
                    'ftm_count_plan',
                    'has_salary_plan',
                ],
                'team' => ['avg_pct', 'rank', 'size', 'members'],
            ]);
    }

    public function test_meta_income_source_is_won_deals(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi')
            ->assertOk()
            ->assertJsonPath('meta.income_source', 'won_deals');
    }

    // -------------------------------------------------------------------------
    // No salary plan → null score (undefined), neutral badge
    // -------------------------------------------------------------------------

    public function test_kpi_no_salary_plan_returns_null_score(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $response->assertJsonPath('personal.has_salary_plan', false);
        $response->assertJsonPath('personal.income_plan_kopecks', 0);
        // No plan → score is undefined (null), not a misleading 0/100.
        $response->assertJsonPath('personal.score_pct', null);
        $response->assertJsonPath('personal.score_badge', 'none');
        $response->assertJsonPath('personal.ftm_count_plan', null);
    }

    public function test_kpi_no_plan_but_won_deal_is_null_not_100(): void
    {
        // The degenerate case: a won deal with NO plan must NOT read as 100%
        // (misleading green). It scores null with a neutral 'none' badge.
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();

        $this->wonDeal($manager, $wonStage, 12_000_000);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $response->assertJsonPath('personal.income_fact_kopecks', 12_000_000);
        $response->assertJsonPath('personal.score_pct', null);
        $response->assertJsonPath('personal.score_badge', 'none');
        $response->assertJsonPath('personal.has_salary_plan', false);
    }

    // -------------------------------------------------------------------------
    // score_pct computed from won deals
    // -------------------------------------------------------------------------

    public function test_score_pct_computed_from_won_deals(): void
    {
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();

        // 3 deals × 5_000_000 = 15_000_000 kopecks fact
        $this->wonDeal($manager, $wonStage, 5_000_000);
        $this->wonDeal($manager, $wonStage, 5_000_000);
        $this->wonDeal($manager, $wonStage, 5_000_000);

        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 20_000_000, // 200 000 RUB plan
            'personal_ftm_plan' => 5,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        // 15_000_000 / 20_000_000 = 75%
        $response->assertJsonPath('personal.income_fact_kopecks', 15_000_000);
        $response->assertJsonPath('personal.income_plan_kopecks', 20_000_000);
        $response->assertJsonPath('personal.score_pct', 75);
        $response->assertJsonPath('personal.score_badge', 'danger');
        $response->assertJsonPath('personal.has_salary_plan', true);
    }

    public function test_score_badge_is_success_at_100_percent(): void
    {
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();

        $this->wonDeal($manager, $wonStage, 20_000_000);

        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 20_000_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi')
            ->assertOk()
            ->assertJsonPath('personal.score_badge', 'success');
    }

    // -------------------------------------------------------------------------
    // Period filter
    // -------------------------------------------------------------------------

    public function test_kpi_period_current_month(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi?period=current_month')->assertOk();

        $response->assertJsonPath('meta.period.from', now()->startOfMonth()->toDateString());
        $response->assertJsonPath('meta.period.to', now()->endOfMonth()->toDateString());
    }

    public function test_kpi_period_last_month(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi?period=last_month')->assertOk();

        $expectedFrom = now()->startOfMonth()->subMonth()->toDateString();
        $response->assertJsonPath('meta.period.from', $expectedFrom);
    }

    public function test_kpi_period_yyyy_mm_format(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi?period=2026-05')
            ->assertOk()
            ->assertJsonPath('meta.period.from', '2026-05-01')
            ->assertJsonPath('meta.period.to', '2026-05-31');
    }

    public function test_kpi_invalid_period_returns_422(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi?period=bad-period')->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Won deals only counted in period (stage_changed_at check)
    // -------------------------------------------------------------------------

    public function test_won_deals_outside_period_not_counted(): void
    {
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();

        // Deal won LAST month — should NOT appear in current_month KPI
        $this->wonDeal(
            $manager,
            $wonStage,
            10_000_000,
            now()->startOfMonth()->subMonth()->midDay()->toDateTimeString()
        );

        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 20_000_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi')
            ->assertOk()
            ->assertJsonPath('personal.income_fact_kopecks', 0)
            ->assertJsonPath('personal.score_pct', 0);
    }

    // -------------------------------------------------------------------------
    // Visibility scope (HD5)
    // -------------------------------------------------------------------------

    public function test_manager_sees_own_kpi_default_period(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi')
            ->assertOk()
            ->assertJsonPath('meta.user.id', $manager->id);
    }

    public function test_manager_cannot_see_other_user_kpi(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/kpi?user_id='.$other->id)
            ->assertForbidden();
    }

    public function test_director_can_see_any_user_kpi(): void
    {
        $director = $this->makeDirector();
        $manager = $this->makeManager();
        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/me/kpi?user_id='.$manager->id)
            ->assertOk()
            ->assertJsonPath('meta.user.id', $manager->id);
    }

    public function test_admin_can_see_any_user_kpi(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/me/kpi?user_id='.$manager->id)
            ->assertOk()
            ->assertJsonPath('meta.user.id', $manager->id);
    }

    // -------------------------------------------------------------------------
    // Team comparison (plan §Б3)
    // -------------------------------------------------------------------------

    public function test_team_members_sorted_by_score_pct_desc(): void
    {
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        $colleague = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $wonStage = $this->wonStage();

        // Manager: 75%
        $this->wonDeal($manager, $wonStage, 15_000_000);
        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 20_000_000,
        ]);

        // Colleague: 100%
        $this->wonDeal($colleague, $wonStage, 20_000_000);
        SalaryPlan::factory()->create([
            'user_id' => $colleague->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 20_000_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $members = $response->json('team.members');
        $this->assertCount(2, $members);
        // First = highest score
        $this->assertGreaterThanOrEqual($members[1]['score_pct'], $members[0]['score_pct']);
    }

    public function test_team_rank_and_avg_pct_correct(): void
    {
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        $colleague = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $wonStage = $this->wonStage();

        // Manager: 50% (5_000_000 / 10_000_000)
        $this->wonDeal($manager, $wonStage, 5_000_000);
        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 10_000_000,
        ]);

        // Colleague: 100% (10_000_000 / 10_000_000)
        $this->wonDeal($colleague, $wonStage, 10_000_000);
        SalaryPlan::factory()->create([
            'user_id' => $colleague->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 10_000_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        // Team: avg(50, 100) = 75
        $response->assertJsonPath('team.avg_pct', 75);
        // Manager with 50% has one higher (100) → rank 2
        $response->assertJsonPath('team.rank', 2);
        $response->assertJsonPath('team.size', 2);
    }

    public function test_team_avg_and_rank_handle_null_member_score(): void
    {
        // A colleague with NO salary plan (null score) must not crash the team
        // aggregation, must sort last, and must not inflate the average.
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        $colleagueNoPlan = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $wonStage = $this->wonStage();

        // Manager: 100% (10_000_000 / 10_000_000)
        $this->wonDeal($manager, $wonStage, 10_000_000);
        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 10_000_000,
        ]);

        // Colleague: a won deal but NO plan → score_pct null, badge 'none'.
        $this->wonDeal($colleagueNoPlan, $wonStage, 8_000_000);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $response->assertJsonPath('team.size', 2);
        // Manager (100%) outranks the null (treated as 0) colleague → rank 1.
        $response->assertJsonPath('team.rank', 1);
        // Median of [0, 100] = 50 (null treated as 0, does not inflate).
        $response->assertJsonPath('team.avg_pct', 50);

        $members = $response->json('team.members');
        $this->assertCount(2, $members);

        // The no-plan member preserves null score + neutral badge, and sorts last.
        $noPlanMember = collect($members)->firstWhere('full_name', $colleagueNoPlan->full_name);
        $this->assertNotNull($noPlanMember);
        $this->assertNull($noPlanMember['score_pct']);
        $this->assertSame('none', $noPlanMember['score_badge']);
        // Sorted DESC by score: measured manager first, null-score colleague last.
        $this->assertSame($manager->full_name, $members[0]['full_name']);
        $this->assertSame($colleagueNoPlan->full_name, $members[1]['full_name']);
    }

    public function test_team_income_fact_excludes_colleagues_for_manager_role(): void
    {
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        $colleague = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $wonStage = $this->wonStage();
        $this->wonDeal($colleague, $wonStage, 10_000_000);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $members = $response->json('team.members');
        // Team has 2 members (manager + colleague); both visible
        $this->assertCount(2, $members);

        // manager role: neither viewer nor colleague should have income_fact_kopecks (Q1 anonymisation)
        foreach ($members as $member) {
            $this->assertArrayNotHasKey(
                'income_fact_kopecks',
                $member,
                'manager role: income_fact_kopecks must not appear in team.members'
            );
        }

        // Viewer flag is set correctly
        $viewer = collect($members)->first(fn ($m) => $m['is_viewer'] === true);
        $this->assertNotNull($viewer);
        $this->assertSame($manager->full_name, $viewer['full_name']);
    }

    public function test_team_director_sees_income_fact_kopecks_of_colleagues(): void
    {
        $dept = $this->makeDept();
        $director = $this->makeDirector($dept->id);
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $wonStage = $this->wonStage();
        $this->wonDeal($manager, $wonStage, 10_000_000);

        Sanctum::actingAs($director, ['*']);

        // Director viewing the manager's KPI
        $response = $this->getJson('/api/me/kpi?user_id='.$manager->id)->assertOk();

        $members = $response->json('team.members');
        // Director is privileged — at least the target user in members has income_fact_kopecks
        $viewerMember = collect($members)->first(fn ($m) => $m['is_viewer'] === true);
        $this->assertNotNull($viewerMember);
        $this->assertArrayHasKey('income_fact_kopecks', $viewerMember);
    }

    // -------------------------------------------------------------------------
    // HD4: empty department
    // -------------------------------------------------------------------------

    public function test_no_department_gives_solo_team(): void
    {
        $manager = $this->makeManager(null); // no dept
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $response->assertJsonPath('team.size', 1);
        $response->assertJsonPath('team.rank', 1);
        $response->assertJsonCount(1, 'team.members');
    }

    // -------------------------------------------------------------------------
    // FTM count
    // -------------------------------------------------------------------------

    public function test_ftm_count_fact_only_counts_all_5_conditions(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        // No activities seeded → FTM = 0
        $response = $this->getJson('/api/me/kpi')->assertOk();
        $response->assertJsonPath('personal.ftm_count_fact', 0);
    }

    // -------------------------------------------------------------------------
    // Role gate — only sales roles reach the cabinet (lawyer/accountant/cfo → 403)
    // -------------------------------------------------------------------------

    public function test_lawyer_cannot_access_cabinet_kpi(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'is_active' => true]);
        Sanctum::actingAs($lawyer, ['*']);

        $this->getJson('/api/me/kpi')->assertForbidden();
    }

    public function test_accountant_cannot_access_cabinet_kpi(): void
    {
        $accountant = User::factory()->create(['role' => Role::Accountant, 'is_active' => true]);
        Sanctum::actingAs($accountant, ['*']);

        $this->getJson('/api/me/kpi')->assertForbidden();
    }

    public function test_manager_director_admin_reach_cabinet_kpi(): void
    {
        foreach ([$this->makeManager(), $this->makeDirector(), $this->makeAdmin()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/me/kpi')->assertOk();
        }
    }

    // -------------------------------------------------------------------------
    // Coherent team derivation — shared line-manager even with NULL department
    // -------------------------------------------------------------------------

    public function test_team_derived_from_shared_manager_when_department_null(): void
    {
        $director = $this->makeDirector();

        // Three managers with NO department but the same line-manager (the real
        // seeded data shape). They must form one team via manager_id.
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => null,
            'manager_id' => $director->id,
            'is_active' => true,
        ]);
        $peerA = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => null,
            'manager_id' => $director->id,
            'is_active' => true,
        ]);
        $peerB = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => null,
            'manager_id' => $director->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        // Team must include all three siblings — not a solo table.
        $response->assertJsonPath('team.size', 3);
        $this->assertCount(3, $response->json('team.members'));

        $names = array_column($response->json('team.members'), 'full_name');
        $this->assertContains($peerA->full_name, $names);
        $this->assertContains($peerB->full_name, $names);
    }

    public function test_team_derived_from_shared_department(): void
    {
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        $colleague = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        $response->assertJsonPath('team.size', 2);
        $names = array_column($response->json('team.members'), 'full_name');
        $this->assertContains($colleague->full_name, $names);
    }

    public function test_director_self_team_includes_director(): void
    {
        $dept = $this->makeDept();
        $director = $this->makeDirector($dept->id);
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'manager_id' => $director->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($director, ['*']);

        // Director viewing their OWN KPI must appear in their own team comparison.
        $response = $this->getJson('/api/me/kpi')->assertOk();

        $viewer = collect($response->json('team.members'))->firstWhere('is_viewer', true);
        $this->assertNotNull($viewer);
        $this->assertSame($director->full_name, $viewer['full_name']);
        $this->assertGreaterThanOrEqual(2, $response->json('team.size'));
    }

    // -------------------------------------------------------------------------
    // Each team member carries a server-computed score_badge (single source)
    // -------------------------------------------------------------------------

    public function test_team_members_carry_score_badge(): void
    {
        $dept = $this->makeDept();
        $manager = $this->makeManager($dept->id);
        User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        foreach ($response->json('team.members') as $member) {
            $this->assertArrayHasKey('score_badge', $member);
            // 'none' covers members with no salary plan (null score).
            $this->assertContains($member['score_badge'], ['success', 'warning', 'danger', 'none']);
        }
    }

    // -------------------------------------------------------------------------
    // score_pct uses the plan converted to base currency (BUG fix)
    // -------------------------------------------------------------------------

    public function test_score_pct_uses_plan_converted_to_base_currency(): void
    {
        $manager = $this->makeManager();
        $wonStage = $this->wonStage();

        // Fact: 9 000 000 kopecks in RUB (base).
        $this->wonDeal($manager, $wonStage, 9_000_000);

        // Plan stored as 100 USD-cents-equivalent (1_000_000 in a non-base ccy).
        // With a USD→RUB rate of 9x the converted plan is 9_000_000 → 100% score.
        SalaryPlan::factory()->create([
            'user_id' => $manager->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'personal_income_plan_kopecks' => 1_000_000,
            'personal_income_plan_currency' => 'USD',
        ]);

        $this->seedRate('USD', 'RUB', 9.0);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/me/kpi')->assertOk();

        // Converted plan 1_000_000 USD-kopecks * 9 = 9_000_000 RUB-kopecks.
        // fact 9_000_000 / plan 9_000_000 = 100%.
        $response->assertJsonPath('personal.income_plan_kopecks', 9_000_000);
        $response->assertJsonPath('personal.score_pct', 100);
    }
}
