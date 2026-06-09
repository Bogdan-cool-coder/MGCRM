<?php

declare(strict_types=1);

namespace Tests\Feature\Chats;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the mini-chat scoped index query: GET /api/chats?scope_type=...
 *
 * Covers:
 *  - scope_type filtering (general / report)
 *  - report_id access checks (403 on cross-company / no access)
 *  - aggregates: last_message_at, user_message_count
 *  - is_active_window logic (zero-msg / under-24h / over-24h / >=10 user msgs)
 *  - limit parameter
 *  - ORDER BY COALESCE(last_message_at, created_at) DESC
 */
class ChatIndexScopedTest extends TestCase
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

    private function makeUser(Company $home, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $home->id, 'role' => $role]],
        ]);
    }

    private function makeReport(Company $company, User $author, array $overrides = []): Report
    {
        return Report::create(array_merge([
            'title'        => ['ru' => 'Test', 'en' => 'Test'],
            'description'  => ['ru' => '', 'en' => ''],
            'config'       => ['primary_model' => 'Deal', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $author->id,
            'company_id'   => $company->id,
        ], $overrides));
    }

    private function makeChat(User $user, Company $company, string $scopeType, ?int $reportId = null, array $overrides = []): Chat
    {
        return Chat::create(array_merge([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'quick_qa',
            'scope_type' => $scopeType,
            'report_id'  => $reportId,
        ], $overrides));
    }

    /**
     * Insert a chat_messages row at a specific created_at — we bypass mass
     * assignment timestamps because the active-window logic is timestamp-
     * sensitive and we need precise control.
     */
    private function addMessage(Chat $chat, User $user, string $role, ?\Carbon\CarbonInterface $at = null): ChatMessage
    {
        $at = $at ?? now();

        $msg = new ChatMessage([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => $role,
            'content'    => 'hi',
            'status'     => 'done',
        ]);
        $msg->created_at = $at;
        $msg->updated_at = $at;
        $msg->save();

        return $msg;
    }

    /** @test */
    public function scope_type_general_returns_only_general_chats(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        $generalChat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $reportChat  = $this->makeChat($user, $company, Chat::SCOPE_REPORT, $report->id);

        $response = $this->actingAs($user)->getJson('/api/chats?scope_type=general');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($generalChat->id, $ids);
        $this->assertNotContains($reportChat->id, $ids);
    }

    /** @test */
    public function scope_type_report_returns_only_chats_of_that_report(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $reportA = $this->makeReport($company, $user);
        $reportB = $this->makeReport($company, $user);

        $chatA1 = $this->makeChat($user, $company, Chat::SCOPE_REPORT, $reportA->id);
        $chatA2 = $this->makeChat($user, $company, Chat::SCOPE_REPORT, $reportA->id);
        $chatB  = $this->makeChat($user, $company, Chat::SCOPE_REPORT, $reportB->id);
        $general = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson("/api/chats?scope_type=report&report_id={$reportA->id}");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$chatA1->id, $chatA2->id], $ids);
        $this->assertNotContains($chatB->id, $ids);
        $this->assertNotContains($general->id, $ids);
    }

    /** @test */
    public function report_id_without_scope_type_report_is_ignored_not_422(): void
    {
        // Documented behaviour: stale report_id in the URL state shouldn't
        // hard-fail the dropdown when it asks for general scope.
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        $general = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $this->makeChat($user, $company, Chat::SCOPE_REPORT, $report->id);

        $response = $this->actingAs($user)->getJson("/api/chats?report_id={$report->id}");

        // No scope_type filter — both chats come back. The stray report_id is
        // simply ignored.
        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertContains($general->id, $ids);
    }

    /** @test */
    public function scope_type_report_requires_report_id(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats?scope_type=report');

        $response->assertStatus(422);
    }

    /** @test */
    public function scope_type_report_with_inaccessible_report_returns_403(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        // User belongs to A only; report belongs to B.
        $user      = $this->makeUser($companyA, 'admin');
        $otherUser = $this->makeUser($companyB, 'admin');
        $foreignReport = $this->makeReport($companyB, $otherUser);

        $response = $this->actingAs($user)->getJson("/api/chats?scope_type=report&report_id={$foreignReport->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function aggregates_last_message_at_and_user_message_count(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);

        // 3 user messages + 2 assistant messages — user_message_count must be 3,
        // last_message_at must be the latest of all roles (assistant @ T+10m).
        $this->addMessage($chat, $user, 'user', now()->subMinutes(30));
        $this->addMessage($chat, $user, 'user', now()->subMinutes(20));
        $this->addMessage($chat, $user, 'assistant', now()->subMinutes(15));
        $this->addMessage($chat, $user, 'user', now()->subMinutes(12));
        $lastAssistant = $this->addMessage($chat, $user, 'assistant', now()->subMinutes(10));

        $response = $this->actingAs($user)->getJson('/api/chats');

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $chat->id);

        $this->assertNotNull($row);
        $this->assertSame(3, $row['user_message_count']);
        $this->assertNotNull($row['last_message_at']);

        $returned = \Carbon\Carbon::parse($row['last_message_at']);
        // last_message_at = MAX over all roles — the latest assistant msg here.
        $this->assertTrue(
            $returned->equalTo($lastAssistant->created_at) || $returned->diffInSeconds($lastAssistant->created_at) < 2,
            "expected last_message_at to match the latest message of any role"
        );
    }

    /** @test */
    public function is_active_window_true_for_freshly_created_chat_with_no_messages(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats');
        $row = collect($response->json())->firstWhere('id', $chat->id);

        $this->assertTrue($row['is_active_window']);
        $this->assertNull($row['last_message_at']);
        $this->assertSame(0, $row['user_message_count']);
    }

    /** @test */
    public function is_active_window_true_when_under_24h_and_under_10_user_messages(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $this->addMessage($chat, $user, 'user', now()->subHours(2));
        $this->addMessage($chat, $user, 'assistant', now()->subHours(1));

        $response = $this->actingAs($user)->getJson('/api/chats');
        $row = collect($response->json())->firstWhere('id', $chat->id);

        $this->assertTrue($row['is_active_window']);
    }

    /** @test */
    public function is_active_window_false_when_last_message_older_than_24h(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $this->addMessage($chat, $user, 'user', now()->subDays(2));

        $response = $this->actingAs($user)->getJson('/api/chats');
        $row = collect($response->json())->firstWhere('id', $chat->id);

        $this->assertFalse($row['is_active_window']);
    }

    /** @test */
    public function is_active_window_false_when_user_message_count_reaches_10(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        for ($i = 0; $i < 10; $i++) {
            $this->addMessage($chat, $user, 'user', now()->subMinutes(60 - $i));
        }

        $response = $this->actingAs($user)->getJson('/api/chats');
        $row = collect($response->json())->firstWhere('id', $chat->id);

        $this->assertSame(10, $row['user_message_count']);
        $this->assertFalse($row['is_active_window']);
    }

    /** @test */
    public function limit_caps_the_result_size(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        for ($i = 0; $i < 15; $i++) {
            $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        }

        $response = $this->actingAs($user)->getJson('/api/chats?limit=5');

        $response->assertOk();
        $this->assertCount(5, $response->json());
    }

    /** @test */
    public function limit_validates_max_50(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats?limit=100');

        $response->assertStatus(422);
    }

    /** @test */
    public function sort_is_by_last_message_at_desc_with_created_at_fallback(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        // Chat 1: created early, last message 1h ago.
        $chat1 = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $chat1->created_at = now()->subDays(5);
        $chat1->save();
        $this->addMessage($chat1, $user, 'user', now()->subHour());

        // Chat 2: created late, no messages — must fall back to created_at.
        $chat2 = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $chat2->created_at = now()->subMinutes(5);
        $chat2->save();

        // Chat 3: created early, last message 10m ago — should sort first.
        $chat3 = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $chat3->created_at = now()->subDays(3);
        $chat3->save();
        $this->addMessage($chat3, $user, 'user', now()->subMinutes(10));

        $response = $this->actingAs($user)->getJson('/api/chats');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->values()->all();

        // Expected order by COALESCE(last_message_at, created_at) DESC:
        //  - chat3 (last_msg = -10m)
        //  - chat2 (no msgs, created -5m → sort key -5m)  -- wait, -5m > -10m
        // Actually -5m (more recent) is "greater" than -10m. So chat2 first.
        // Recalculate sort keys (more-recent = higher):
        //   chat2: -5m       (highest)
        //   chat3: -10m
        //   chat1: -1h       (lowest)
        $this->assertSame([$chat2->id, $chat3->id, $chat1->id], $ids);
    }

    /** @test */
    public function does_not_leak_other_users_chats(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $other   = $this->makeUser($company);

        $mine   = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $theirs = $this->makeChat($other, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats');
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /** @test */
    public function response_includes_scope_type_field(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $this->makeChat($user, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats');
        $response->assertOk();

        $row = $response->json(0);
        $this->assertArrayHasKey('scope_type', $row);
        $this->assertSame(Chat::SCOPE_GENERAL, $row['scope_type']);
        $this->assertArrayHasKey('last_message_at', $row);
        $this->assertArrayHasKey('user_message_count', $row);
        $this->assertArrayHasKey('is_active_window', $row);
    }

    /** @test */
    public function store_persists_scope_type_when_provided_and_defaults_to_general(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        // Default branch.
        $resp1 = $this->actingAs($user)->postJson('/api/chats', ['type' => 'quick_qa']);
        $resp1->assertCreated()->assertJsonPath('scope_type', Chat::SCOPE_GENERAL);

        // Explicit value branch.
        $resp2 = $this->actingAs($user)->postJson('/api/chats', [
            'type'       => 'report_generation',
            'scope_type' => 'report',
        ]);
        $resp2->assertCreated()->assertJsonPath('scope_type', Chat::SCOPE_REPORT);
    }
}
