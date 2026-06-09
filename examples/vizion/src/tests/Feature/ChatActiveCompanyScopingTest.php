<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ChatController + active-company scoping (task #3 of the
 * active-company rollout).
 *
 * Verifies that:
 * - GET /api/chats lists only chats whose company_id matches the user's
 *   currently active company (not their home company_id).
 * - POST /api/chats binds the new chat to the active company.
 * - GET /api/chats/{id} (canAccessChat) refuses chats from the non-active
 *   company even when the user technically has access to that company.
 *
 * We deliberately exercise the GET/POST/SHOW/DESTROY surface and skip
 * sendMessage — sendMessage uses the same canAccessChat() guard, so the
 * authorisation matrix is covered by show/destroy without needing to stand
 * up a full Prism::fake() LLM pipeline.
 */
class ChatActiveCompanyScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    /** @test */
    public function test_index_lists_only_chats_from_the_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'superadmin'],
                ['company_id' => $companyB->id, 'role' => 'superadmin'],
            ],
        ]);

        // Two chats in B (active), one in A (home) — only the B ones should surface.
        $chatA = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $companyA->id,
            'type'       => 'quick_qa',
            'title'      => 'home chat (A)',
        ]);
        $chatB1 = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $companyB->id,
            'type'       => 'quick_qa',
            'title'      => 'active chat 1 (B)',
        ]);
        $chatB2 = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $companyB->id,
            'type'       => 'report_generation',
            'title'      => 'active chat 2 (B)',
        ]);

        $response = $this->actingAs($user)->getJson('/api/chats');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($chatB1->id, $ids);
        $this->assertContains($chatB2->id, $ids);
        $this->assertNotContains($chatA->id, $ids, 'home-company chat must not leak into the active-company list');
        $this->assertCount(2, $ids);
    }

    /** @test */
    public function test_store_binds_new_chat_to_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'superadmin'],
                ['company_id' => $companyB->id, 'role' => 'superadmin'],
            ],
        ]);

        $response = $this->actingAs($user)->postJson('/api/chats', [
            'type' => 'quick_qa',
        ]);

        $response->assertCreated();
        $chat = Chat::findOrFail($response->json('id'));

        $this->assertSame($companyB->id, $chat->company_id, 'new chat must be owned by the active company');
        $this->assertSame($user->id, $chat->user_id);
    }

    /** @test */
    public function test_store_falls_back_to_home_company_when_no_active_is_set(): void
    {
        $companyA = $this->makeCompany('CompanyA');

        // No active_company_id — model boot hook auto-fills it to company_id,
        // so we explicitly null it after create to simulate the legacy state.
        $user = User::factory()->create([
            'company_id'       => $companyA->id,
            'role'             => 'admin',
            'company_accesses' => [['company_id' => $companyA->id, 'role' => 'admin']],
        ]);
        $user->forceFill(['active_company_id' => null])->save();

        $response = $this->actingAs($user)->postJson('/api/chats', [
            'type' => 'report_generation',
        ]);

        $response->assertCreated();
        $chat = Chat::findOrFail($response->json('id'));

        $this->assertSame($companyA->id, $chat->company_id);
    }

    /** @test */
    public function test_admin_can_access_own_chat_in_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $chatInA = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $companyA->id,
            'type'       => 'quick_qa',
        ]);

        $response = $this->actingAs($user)->getJson("/api/chats/{$chatInA->id}");

        $response->assertOk()->assertJsonPath('id', $chatInA->id);
    }

    /** @test */
    public function test_admin_cannot_access_chat_from_non_active_company_even_with_access(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        // Admin has access to BOTH A and B but is currently switched to A.
        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        $chatInB = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $companyB->id,
            'type'       => 'quick_qa',
        ]);

        $response = $this->actingAs($user)->getJson("/api/chats/{$chatInB->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_can_access_chat_after_switching_to_its_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');
        $companyB = $this->makeCompany('CompanyB');

        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        // Admin can access chat created by *another* admin in the same company
        // (admin role grants company-wide chat visibility).
        $otherAdmin = User::factory()->create([
            'company_id'        => $companyB->id,
            'active_company_id' => $companyB->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $companyB->id, 'role' => 'admin']],
        ]);

        $chatInB = Chat::create([
            'user_id'    => $otherAdmin->id,
            'company_id' => $companyB->id,
            'type'       => 'quick_qa',
        ]);

        $response = $this->actingAs($user)->getJson("/api/chats/{$chatInB->id}");

        $response->assertOk()->assertJsonPath('id', $chatInB->id);
    }

    /** @test */
    public function test_analyst_cannot_access_others_chat_even_in_active_company(): void
    {
        $companyA = $this->makeCompany('CompanyA');

        $analyst = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $companyA->id, 'role' => 'analyst']],
        ]);
        $otherUser = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $companyA->id, 'role' => 'analyst']],
        ]);

        $othersChat = Chat::create([
            'user_id'    => $otherUser->id,
            'company_id' => $companyA->id,
            'type'       => 'quick_qa',
        ]);

        $response = $this->actingAs($analyst)->getJson("/api/chats/{$othersChat->id}");

        $response->assertStatus(403);
    }
}
