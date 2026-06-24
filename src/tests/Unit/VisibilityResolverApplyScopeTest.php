<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VisibilityResolver::applyScope — the reusable query-scope helper the CRM /
 * Contracts list+export services call (the counterpart of
 * DealService::scopedQuery). Verifies All / Own / Department behave coherently
 * across one or many owner columns. Backed by the real Company table
 * (owner_user_id + responsible_user_id + department_id) so the SQL actually runs.
 */
class VisibilityResolverApplyScopeTest extends TestCase
{
    use RefreshDatabase;

    private VisibilityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(VisibilityResolver::class);
    }

    public function test_all_scope_returns_every_row(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->count(3)->create(['owner_user_id' => $other->id]);

        $rows = $this->resolver
            ->applyScope(Company::query(), $admin, ['owner_user_id'])
            ->get();

        $this->assertCount(3, $rows);
    }

    public function test_own_scope_returns_only_owned_rows(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $mine = Company::factory()->create(['owner_user_id' => $manager->id]);
        Company::factory()->count(2)->create(['owner_user_id' => $other->id]);

        $rows = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()->id);
    }

    public function test_own_scope_ors_multiple_owner_columns(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $owned = Company::factory()->create([
            'owner_user_id' => $manager->id,
            'responsible_user_id' => $other->id,
        ]);
        $responsibleFor = Company::factory()->create([
            'owner_user_id' => $other->id,
            'responsible_user_id' => $manager->id,
        ]);
        // Neither owned nor responsible — must be excluded.
        Company::factory()->create([
            'owner_user_id' => $other->id,
            'responsible_user_id' => $other->id,
        ]);

        $ids = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id', 'responsible_user_id'])
            ->pluck('id')
            ->all();

        sort($ids);
        $expected = [$owned->id, $responsibleFor->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_own_scope_does_not_leak_when_a_filter_is_chained_after(): void
    {
        // Guards against the classic OR-precedence bug: the owner predicate is
        // wrapped in its own nested where(), so a later ->where() AND-combines
        // rather than widening the result via a dangling OR.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->create(['owner_user_id' => $manager->id, 'name' => 'Mine A']);
        Company::factory()->create(['owner_user_id' => $other->id, 'name' => 'Theirs A']);

        $rows = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id', 'responsible_user_id'])
            ->where('name', 'Theirs A')
            ->get();

        // 'Theirs A' is not owned by the manager, so AND with the scope = empty.
        $this->assertCount(0, $rows);
    }

    public function test_department_scope_includes_subtree_and_own_rows(): void
    {
        // No role resolves to Department today (M1 reserve), so the scope is
        // passed explicitly to exercise the wired-but-dormant branch.
        $parentDept = Department::factory()->create(['name' => 'Sales']);
        $childDept = Department::factory()->create(['name' => 'Inside Sales', 'parent_id' => $parentDept->id]);
        $foreignDept = Department::factory()->create(['name' => 'Legal']);

        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $parentDept->id,
        ]);
        $teammateOther = User::factory()->create(['role' => Role::Manager]);

        $ownDeptRow = Company::factory()->create([
            'owner_user_id' => $teammateOther->id,
            'department_id' => $parentDept->id,
        ]);
        $childDeptRow = Company::factory()->create([
            'owner_user_id' => $teammateOther->id,
            'department_id' => $childDept->id,
        ]);
        // Foreign department, but owned by the manager → still visible (own rule).
        $ownedForeignRow = Company::factory()->create([
            'owner_user_id' => $manager->id,
            'department_id' => $foreignDept->id,
        ]);
        // Foreign department, not owned → excluded.
        Company::factory()->create([
            'owner_user_id' => $teammateOther->id,
            'department_id' => $foreignDept->id,
        ]);

        $ids = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id'], 'department_id', VisibilityScope::Department)
            ->pluck('id')
            ->all();

        sort($ids);
        $expected = [$ownDeptRow->id, $childDeptRow->id, $ownedForeignRow->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_department_scope_without_department_column_degrades_to_own_not_all(): void
    {
        // A model with no department anchor must NOT silently widen to All when
        // the resolved scope is Department — it must collapse to Own.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $mine = Company::factory()->create(['owner_user_id' => $manager->id]);
        Company::factory()->create(['owner_user_id' => $other->id]);

        $rows = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id'], null, VisibilityScope::Department)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()->id);
    }

    public function test_explicit_all_scope_overrides_role_resolution(): void
    {
        // A manager (role → Own) with an explicit All scope sees everything —
        // proves the explicit-scope param is honored over role resolution.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->count(2)->create(['owner_user_id' => $other->id]);

        $rows = $this->resolver
            ->applyScope(Company::query(), $manager, ['owner_user_id'], null, VisibilityScope::All)
            ->get();

        $this->assertCount(2, $rows);
    }
}
