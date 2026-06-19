<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Services\TeamResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamResolver (Slice 3, spec §8): chat→team, slug/caller→manager, admin-gating,
 * date/slug argument parsing.
 */
class TeamResolverTest extends TestCase
{
    use RefreshDatabase;

    private TeamResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TeamResolver;
        config()->set('salespulse.timezone', 'Asia/Dubai');
    }

    private function configureTeam(): void
    {
        config()->set('salespulse.teams', [[
            'chat_id' => '-1001',
            'name' => 'MACRO Global',
            'pipelines' => [7, 8],
            'admins' => ['Bogdan_MACRO'],
            'managers' => [
                ['user_id' => 101, 'tg' => 'ilyarogov', 'name' => 'Илья Рогов'],
                ['user_id' => 102, 'tg' => 'olesya', 'name' => 'Олеся Моисеева'],
            ],
        ]]);
    }

    public function test_team_by_chat_resolves_configured_chat(): void
    {
        $this->configureTeam();

        $team = $this->resolver->teamByChat('-1001');

        $this->assertNotNull($team);
        $this->assertSame('MACRO Global', $team->name);
        $this->assertSame([7, 8], $team->pipelineIds);
    }

    public function test_foreign_chat_resolves_to_null(): void
    {
        $this->configureTeam();

        $this->assertNull($this->resolver->teamByChat('-9999'));
        $this->assertNull($this->resolver->teamByChat(null));
    }

    public function test_is_admin_is_case_insensitive(): void
    {
        $this->configureTeam();
        $team = $this->resolver->teamByChat('-1001');

        $this->assertTrue($this->resolver->isAdmin($team, 'bogdan_macro'));
        $this->assertTrue($this->resolver->isAdmin($team, 'Bogdan_MACRO'));
        $this->assertFalse($this->resolver->isAdmin($team, 'ilyarogov'));
        $this->assertFalse($this->resolver->isAdmin($team, null));
    }

    public function test_manager_by_slug_matches_username_then_name_then_id(): void
    {
        $this->configureTeam();
        $team = $this->resolver->teamByChat('-1001');

        $this->assertSame(101, $team->managerBySlug('ilyarogov')->userId);
        $this->assertSame(101, $team->managerBySlug('ILYAROGOV')->userId);   // case-insensitive
        $this->assertSame(102, $team->managerBySlug('Олеся Моисеева')->userId); // by name
        $this->assertSame(101, $team->managerBySlug('101')->userId);          // by numeric id
        $this->assertNull($team->managerBySlug('nobody'));
    }

    public function test_caller_manager_resolves_by_tg_username(): void
    {
        $this->configureTeam();
        $team = $this->resolver->teamByChat('-1001');

        $this->assertSame(102, $this->resolver->callerManager($team, 'olesya')->userId);
        $this->assertNull($this->resolver->callerManager($team, 'bogdan_macro')); // admin, not a roster manager
    }

    public function test_resolve_target_user_admin_acts_for_another(): void
    {
        $this->configureTeam();
        $team = $this->resolver->teamByChat('-1001');

        $ilya = User::factory()->create(['id' => 101]);

        // Admin with a slug → that manager's User.
        $target = $this->resolver->resolveTargetUser($team, 'bogdan_macro', ['ilyarogov']);
        $this->assertNotNull($target);
        $this->assertSame($ilya->id, $target->id);
    }

    public function test_resolve_target_user_non_admin_acts_for_self_only(): void
    {
        $this->configureTeam();
        $team = $this->resolver->teamByChat('-1001');

        User::factory()->create(['id' => 101]);
        $olesya = User::factory()->create(['id' => 102]);

        // A non-admin manager passing someone else's slug still resolves to themselves.
        $target = $this->resolver->resolveTargetUser($team, 'olesya', ['ilyarogov']);
        $this->assertSame($olesya->id, $target->id);
    }

    public function test_parse_args_splits_date_and_slug(): void
    {
        $today = CarbonImmutable::now('Asia/Dubai')->startOfDay();

        // slug + ISO date
        [$date, $slug] = $this->resolver->parseArgs(['ilyarogov', '2026-06-19']);
        $this->assertSame('2026-06-19', $date->toDateString());
        $this->assertSame('ilyarogov', $slug);

        // date only
        [$date, $slug] = $this->resolver->parseArgs(['18.06.2026']);
        $this->assertSame('2026-06-18', $date->toDateString());
        $this->assertNull($slug);

        // no args → today, no slug
        [$date, $slug] = $this->resolver->parseArgs([]);
        $this->assertSame($today->toDateString(), $date->toDateString());
        $this->assertNull($slug);
    }

    public function test_parse_date_token_keywords_and_formats(): void
    {
        $today = CarbonImmutable::now('Asia/Dubai')->startOfDay();

        $this->assertSame($today->toDateString(), $this->resolver->parseDateToken('today')->toDateString());
        $this->assertSame($today->subDay()->toDateString(), $this->resolver->parseDateToken('yesterday')->toDateString());
        $this->assertSame('2026-06-19', $this->resolver->parseDateToken('2026-06-19')->toDateString());
        $this->assertSame('2026-06-19', $this->resolver->parseDateToken('19.06.2026')->toDateString());
        $this->assertNull($this->resolver->parseDateToken('ilyarogov'));
    }
}
