<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * IAM-1 (second pass) — proves the domain authz gates that used to be inline
 * `$user->role === Role::X` checks now resolve through spatie PERMISSIONS on the
 * `sanctum` guard, over the Bearer-token HTTP path.
 *
 * For each representative gate (contracts, catalog, inbox, pipelines, onboarding)
 * this asserts:
 *   - a role that HOLDS the permission is NOT 403 (authz passes);
 *   - a role that lacks it is 403;
 *   - revoking the spatie permission from a holder (role enum untouched) flips it
 *     to 403 — the proof that spatie, not the role enum, is authoritative.
 *
 * The Sanctum token carries the wildcard ability `['*']`; spatie permissions are
 * resolved by the active (sanctum) guard independently of token abilities, so a
 * 403 here is the spatie permission gate, not a token-ability rejection.
 */
class PermissionAuthzOverSanctumTest extends TestCase
{
    use RefreshDatabase;

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // -------------------------------------------------------------------------
    // catalog.manage  (admin, director) — ProductPolicy::create
    // -------------------------------------------------------------------------

    public function test_catalog_manage_admin_passes_director_passes_manager_403(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        $payload = ['code' => 'iam1_prod', 'name' => 'IAM1', 'group_id' => null, 'pricing_type' => 'fixed'];

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/catalog/products', $payload)->assertCreated();

        Sanctum::actingAs($director, ['*']);
        // director holds catalog.manage → authz passes (not 403).
        $this->postJson('/api/catalog/products', ['code' => 'iam1_prod2', 'name' => 'IAM1b', 'group_id' => null, 'pricing_type' => 'fixed'])
            ->assertCreated();

        Sanctum::actingAs($manager, ['*']);
        $this->postJson('/api/catalog/products', $payload)->assertForbidden();
    }

    public function test_revoking_catalog_manage_flips_admin_to_403(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/catalog/products', ['code' => 'iam1_a', 'name' => 'A', 'group_id' => null, 'pricing_type' => 'fixed'])
            ->assertCreated();

        // Revoke the spatie permission from the admin ROLE (the role-enum mirror is
        // untouched). If authz still resolved off the role enum this would stay 201.
        $adminRole = \Spatie\Permission\Models\Role::findByName(Role::Admin->value, 'sanctum');
        $adminRole->revokePermissionTo('catalog.manage');
        $this->forgetPermissionCache();

        Sanctum::actingAs($admin->fresh(), ['*']);
        $this->postJson('/api/catalog/products', ['code' => 'iam1_b', 'name' => 'B', 'group_id' => null, 'pricing_type' => 'fixed'])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // pipelines.manage  (admin, director) — PipelinePolicy::create
    // -------------------------------------------------------------------------

    public function test_pipelines_manage_director_passes_manager_403(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($director, ['*']);
        $this->postJson('/api/pipelines', ['name' => 'IAM1 Pipeline'])->assertCreated();

        Sanctum::actingAs($manager, ['*']);
        $this->postJson('/api/pipelines', ['name' => 'IAM1 Pipeline 2'])->assertForbidden();
    }

    public function test_revoking_pipelines_manage_flips_director_to_403(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);

        Sanctum::actingAs($director, ['*']);
        $this->postJson('/api/pipelines', ['name' => 'IAM1 P'])->assertCreated();

        $directorRole = \Spatie\Permission\Models\Role::findByName(Role::Director->value, 'sanctum');
        $directorRole->revokePermissionTo('pipelines.manage');
        $this->forgetPermissionCache();

        Sanctum::actingAs($director->fresh(), ['*']);
        $this->postJson('/api/pipelines', ['name' => 'IAM1 P2'])->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // inbox.manage  (admin, director) — InboundMessagePolicy / ChannelPolicy
    // -------------------------------------------------------------------------

    public function test_inbox_manage_director_reads_log_manager_403(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($director, ['*']);
        $this->getJson('/api/inbox')->assertOk();

        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/inbox')->assertForbidden();
    }

    public function test_inbox_manage_channel_create_manager_403_admin_passes(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        $payload = ['name' => 'IAM1 Channel', 'kind' => config('inbox.channel_kinds')[0]];

        Sanctum::actingAs($manager, ['*']);
        $this->postJson('/api/channels', $payload)->assertForbidden();

        Sanctum::actingAs($admin, ['*']);
        // admin holds inbox.manage → authz passes (created, or 422 only on body).
        $response = $this->postJson('/api/channels', $payload);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_revoking_inbox_manage_flips_admin_channel_create_to_403(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $adminRole = \Spatie\Permission\Models\Role::findByName(Role::Admin->value, 'sanctum');
        $adminRole->revokePermissionTo('inbox.manage');
        $this->forgetPermissionCache();

        Sanctum::actingAs($admin->fresh(), ['*']);
        $this->postJson('/api/channels', ['name' => 'X', 'kind' => config('inbox.channel_kinds')[0]])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // contracts.approve / contracts.admin / contracts.templates.use
    // -------------------------------------------------------------------------

    public function test_contracts_message_template_use_visibility(): void
    {
        // contracts.templates.use is granted to admin/lawyer/director/manager but
        // NOT accountant/cfo.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $accountant = User::factory()->create(['role' => Role::Accountant]);

        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/message-templates')->assertOk();

        Sanctum::actingAs($accountant, ['*']);
        $this->getJson('/api/message-templates')->assertForbidden();
    }

    public function test_contracts_message_template_create_lawyer_passes_manager_403(): void
    {
        // contracts.approve (create) is admin/lawyer only — manager can view but
        // not create.
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        // Valid body so the controller's authorize('create') gate is reached
        // (the FormRequest validates title+body BEFORE the policy check).
        $payload = ['title' => 'IAM1 Template', 'body' => 'Hello'];

        Sanctum::actingAs($manager, ['*']);
        $this->postJson('/api/message-templates', $payload)->assertForbidden();

        Sanctum::actingAs($lawyer, ['*']);
        $this->postJson('/api/message-templates', $payload)->assertCreated();
    }

    public function test_revoking_contracts_approve_flips_lawyer_template_create_to_403(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $lawyerRole = \Spatie\Permission\Models\Role::findByName(Role::Lawyer->value, 'sanctum');
        $lawyerRole->revokePermissionTo('contracts.approve');
        $this->forgetPermissionCache();

        Sanctum::actingAs($lawyer->fresh(), ['*']);
        $this->postJson('/api/message-templates', ['title' => 'IAM1', 'body' => 'Hello'])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // onboarding.manage  (admin, director) — CoursePolicy::create
    // -------------------------------------------------------------------------

    public function test_onboarding_manage_manager_403(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($manager, ['*']);
        $this->postJson('/api/admin/onboarding/courses', ['title' => 'IAM1 Course'])
            ->assertForbidden();
    }

    public function test_onboarding_manage_director_not_403(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);

        Sanctum::actingAs($director, ['*']);
        // director holds onboarding.manage → authz passes (created or 422 on body).
        $response = $this->postJson('/api/admin/onboarding/courses', ['title' => 'IAM1 Course']);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Sanity: the guard the request authenticates with IS sanctum, and the
    // permission is registered on it (the historical IAM-1 guard bug).
    // -------------------------------------------------------------------------

    public function test_permissions_are_registered_on_the_sanctum_guard(): void
    {
        $this->assertSame('sanctum', config('auth.defaults.guard'));

        $perm = Permission::findByName('catalog.manage', 'sanctum');
        $this->assertSame('sanctum', $perm->guard_name);

        // A holder resolves it via the active guard over the Bearer path.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->assertTrue($admin->can('catalog.manage'));

        // The Pipeline/Channel imports are exercised above; reference them so the
        // class stays import-clean under Pint.
        $this->assertTrue(class_exists(Pipeline::class));
        $this->assertTrue(class_exists(Channel::class));
    }
}
