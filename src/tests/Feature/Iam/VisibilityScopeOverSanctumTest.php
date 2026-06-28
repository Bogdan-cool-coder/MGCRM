<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * IAM-2 — the two remaining VISIBILITY-SCOPE sites left by IAM-1 now resolve
 * through spatie PERMISSIONS on the `sanctum` guard, not the `users.role` enum.
 * These checks decide WHICH ROWS a user sees in a list (not whether they may
 * act), so they live in the domain Services (read-scope), not in Policies.
 *
 *   SITE 1 — PipelineService: `pipelines.view-all` (admin, director) controls
 *            "sees ALL pipelines vs only own"; a per-pipeline `visible_role`
 *            row-identity match now reads via spatie `$user->hasRole($name)`.
 *   SITE 2 — DocumentService::list: `contracts.view-all` (admin, lawyer,
 *            director) controls "sees ALL documents vs only own".
 *
 * Behaviour parity is the invariant: the same users see the exact same rows as
 * before. The revocation cases (permission removed, role-enum intact) are the
 * proof that spatie — not the role enum — is now authoritative for these scopes.
 */
class VisibilityScopeOverSanctumTest extends TestCase
{
    use RefreshDatabase;

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function pipelineNames(): array
    {
        return collect($this->getJson('/api/pipelines')->assertOk()->json('data'))
            ->pluck('name')
            ->all();
    }

    // =====================================================================
    // SITE 1 — PipelineService visibility (pipelines.view-all + hasRole)
    // =====================================================================

    public function test_manager_without_view_all_sees_only_unrestricted_pipelines(): void
    {
        $this->seed(PipelineSeeder::class); // "Продажи" — unrestricted

        Pipeline::factory()->create([
            'name' => 'Directors only',
            'visible_role' => Role::Director->value,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $names = $this->pipelineNames();

        $this->assertContains('Продажи', $names);          // unrestricted → visible
        $this->assertNotContains('Directors only', $names); // restricted away
    }

    public function test_admin_and_director_with_view_all_see_every_pipeline(): void
    {
        $this->seed(PipelineSeeder::class);

        Pipeline::factory()->create([
            'name' => 'Directors only',
            'visible_role' => Role::Director->value,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $this->assertContains('Directors only', $this->pipelineNames());

        Sanctum::actingAs(User::factory()->create(['role' => Role::Director]), ['*']);
        $this->assertContains('Directors only', $this->pipelineNames());
    }

    public function test_pipeline_visible_role_matches_user_with_that_role_only(): void
    {
        // A pipeline configured visible to role X is seen by a user with role X
        // and NOT by a user with a different (non-view-all) role. This is the
        // hasRole() row-identity branch — resolved through spatie, not the enum.
        Pipeline::factory()->create([
            'name' => 'Managers funnel',
            'visible_role' => Role::Manager->value,
        ]);

        // Manager (role X) → visible.
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $this->assertContains('Managers funnel', $this->pipelineNames());

        // Accountant (role Y, no view-all) → NOT visible.
        Sanctum::actingAs(User::factory()->create(['role' => Role::Accountant]), ['*']);
        $this->assertNotContains('Managers funnel', $this->pipelineNames());
    }

    public function test_revoking_pipelines_view_all_flips_director_to_own_scope(): void
    {
        // A director holding pipelines.view-all sees a role-restricted funnel...
        $this->seed(PipelineSeeder::class);
        Pipeline::factory()->create([
            'name' => 'Admins only',
            'visible_role' => Role::Admin->value,
        ]);

        $director = User::factory()->create(['role' => Role::Director]);

        Sanctum::actingAs($director, ['*']);
        $this->assertContains('Admins only', $this->pipelineNames());

        // ...revoke the permission from the director ROLE (role enum untouched).
        // If the scope still resolved off the enum the funnel would stay visible.
        SpatieRole::findByName(Role::Director->value, 'sanctum')
            ->revokePermissionTo('pipelines.view-all');
        $this->forgetPermissionCache();

        Sanctum::actingAs($director->fresh(), ['*']);
        $names = $this->pipelineNames();

        // Director (role intact) is now scoped: the "Admins only" funnel — whose
        // visible_role does NOT match Director — disappears. The unrestricted
        // "Продажи" funnel stays. Proves spatie is authoritative for the scope.
        $this->assertNotContains('Admins only', $names);
        $this->assertContains('Продажи', $names);
    }

    // =====================================================================
    // SITE 2 — DocumentService::list visibility (contracts.view-all)
    // =====================================================================

    /** @return list<int> author ids visible in the documents list */
    private function listedAuthorIds(): array
    {
        return collect($this->getJson('/api/documents')->assertOk()->json('data'))
            ->pluck('author_user_id')
            ->unique()
            ->values()
            ->all();
    }

    public function test_manager_without_view_all_sees_only_own_documents(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $manager->id]);
        Document::factory()->count(3)->create(['author_user_id' => $other->id]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertSame([$manager->id], $this->listedAuthorIds());
    }

    public function test_admin_lawyer_director_with_view_all_see_all_documents(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->count(3)->create(['author_user_id' => $manager->id]);

        foreach ([Role::Admin, Role::Lawyer, Role::Director] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            Document::factory()->create(['author_user_id' => $viewer->id]);

            Sanctum::actingAs($viewer, ['*']);
            $authors = $this->listedAuthorIds();

            // Sees other authors' documents, not just their own.
            $this->assertContains($manager->id, $authors, "{$role->value} should see manager's docs");
        }
    }

    public function test_revoking_contracts_view_all_flips_admin_to_own_scope(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $admin->id]);
        Document::factory()->count(3)->create(['author_user_id' => $manager->id]);

        // With the permission, admin sees both authors' documents.
        Sanctum::actingAs($admin, ['*']);
        $this->assertContains($manager->id, $this->listedAuthorIds());

        // Revoke contracts.view-all from the admin ROLE (role enum untouched).
        SpatieRole::findByName(Role::Admin->value, 'sanctum')
            ->revokePermissionTo('contracts.view-all');
        $this->forgetPermissionCache();

        Sanctum::actingAs($admin->fresh(), ['*']);

        // Now admin is scoped to own documents only — proves spatie authoritative.
        $this->assertSame([$admin->id], $this->listedAuthorIds());
    }
}
