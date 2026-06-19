<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\SalesPulse\Services\TestModeResolver;
use Database\Seeders\AmoPipelineSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesPulseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TestModeResolver — the building blocks of the private-chat test mode: the
 * enabled/admin gate, canonical-name → pipeline-id resolution (with the
 * 'all_active_sales' marker + empty fallback) and email → user_id roster resolution
 * (a missing account is dropped). Touches the DB but not Nutgram.
 */
class TestModeResolverTest extends TestCase
{
    use RefreshDatabase;

    private TestModeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);
        $this->seed(SalesPulseDemoSeeder::class);

        config()->set('salespulse.test_mode.enabled', true);
        config()->set('salespulse.test_mode.admins', ['Bogdan_MACRO']);

        $this->resolver = app(TestModeResolver::class);
    }

    public function test_admin_gate_is_case_insensitive_and_respects_the_flag(): void
    {
        $this->assertTrue($this->resolver->isTestAdmin('bogdan_macro'));
        $this->assertTrue($this->resolver->isTestAdmin('BOGDAN_MACRO'));
        $this->assertFalse($this->resolver->isTestAdmin('someone_else'));
        $this->assertFalse($this->resolver->isTestAdmin(null));

        config()->set('salespulse.test_mode.enabled', false);
        $this->assertFalse($this->resolver->isTestAdmin('Bogdan_MACRO'));
    }

    public function test_applies_requires_enabled_private_and_admin(): void
    {
        $this->assertTrue($this->resolver->applies(isPrivateChat: true, tgUsername: 'Bogdan_MACRO'));
        $this->assertFalse($this->resolver->applies(isPrivateChat: false, tgUsername: 'Bogdan_MACRO'));
        $this->assertFalse($this->resolver->applies(isPrivateChat: true, tgUsername: 'stranger'));
    }

    public function test_resolves_canonical_pipeline_names_to_ids(): void
    {
        config()->set('salespulse.test_mode.team.pipelines', ['MACRO Global', 'MACRO AI Global']);

        $expected = Pipeline::query()
            ->sales()
            ->whereIn('name', ['MACRO Global', 'MACRO AI Global'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing($expected, $this->resolver->resolvePipelineIds());
        $this->assertNotEmpty($this->resolver->resolvePipelineIds());
    }

    public function test_all_active_sales_marker_returns_every_active_sales_pipeline(): void
    {
        config()->set('salespulse.test_mode.team.pipelines', ['all_active_sales']);

        $expected = Pipeline::query()
            ->sales()
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing($expected, $this->resolver->resolvePipelineIds());
    }

    public function test_unknown_pipeline_names_fall_back_to_active_sales(): void
    {
        config()->set('salespulse.test_mode.team.pipelines', ['Nonexistent Funnel']);

        $this->assertNotEmpty($this->resolver->resolvePipelineIds());
    }

    public function test_resolves_managers_by_email_to_seeded_user_ids(): void
    {
        $managers = $this->resolver->resolveManagers();

        $this->assertCount(3, $managers);

        $manager1 = User::query()->where('email', 'manager1@mgcrm.test')->firstOrFail();
        $this->assertSame((int) $manager1->id, $managers[0]->userId);
        $this->assertSame('manager1', $managers[0]->tg);
        $this->assertSame('Менеджер 1', $managers[0]->name);
    }

    public function test_missing_test_account_is_dropped(): void
    {
        config()->set('salespulse.test_mode.team.managers', [
            ['email' => 'manager1@mgcrm.test', 'tg' => 'manager1', 'name' => 'Менеджер 1'],
            ['email' => 'ghost@mgcrm.test', 'tg' => 'ghost', 'name' => 'Призрак'],
        ]);

        $managers = $this->resolver->resolveManagers();

        $this->assertCount(1, $managers);
        $this->assertSame('manager1', $managers[0]->tg);
    }

    public function test_team_uses_private_chat_id_and_caller_as_admin(): void
    {
        $team = $this->resolver->team(privateChatId: 7001, adminUsername: 'Bogdan_MACRO');

        $this->assertSame('7001', $team->chatId);
        $this->assertSame('ТЕСТ', $team->name);
        $this->assertTrue($team->isAdmin('Bogdan_MACRO'));
        $this->assertNotEmpty($team->managers);
        $this->assertNotEmpty($team->pipelineIds);
    }
}
