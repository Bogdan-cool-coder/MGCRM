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
 * Feature tests for the mini-chat auto-resume endpoint: GET /api/chats/resume.
 *
 * Contract:
 *  - 200 OK + ChatDetailDto when the most recently active chat in the scope
 *    is within its active window (last_message_at < 24h ago AND user msg
 *    count < 10, OR 0 messages at all).
 *  - 204 No Content when nothing qualifies (no chats / all over the cutoffs).
 *  - 403 when scope_type=report and the report belongs to another company.
 *  - 422 when scope_type is missing / invalid, or scope_type=report without
 *    report_id.
 *
 * The endpoint must reuse the same active-window predicate as the index
 * endpoint (covered by ChatIndexScopedTest) — chats with last_message_at
 * outside the window are explicitly skipped even if they're the freshest
 * in the scope.
 */
class ChatResumeTest extends TestCase
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
     * Mirror of ChatIndexScopedTest::addMessage — bypass mass-assignment
     * timestamps because the active-window predicate is timestamp-sensitive.
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
    public function returns_204_when_no_chats_in_scope(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertNoContent();
    }

    /** @test */
    public function returns_204_when_last_message_older_than_24h(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $this->addMessage($chat, $user, 'user', now()->subDays(2));

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertNoContent();
    }

    /** @test */
    public function returns_204_when_user_message_count_reaches_10(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        for ($i = 0; $i < 10; $i++) {
            $this->addMessage($chat, $user, 'user', now()->subMinutes(60 - $i));
        }

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertNoContent();
    }

    /** @test */
    public function returns_200_with_freshly_created_chat_having_no_messages(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertOk()
            ->assertJsonPath('id', $chat->id)
            ->assertJsonPath('user_message_count', 0)
            ->assertJsonPath('is_active_window', true)
            ->assertJsonPath('scope_type', Chat::SCOPE_GENERAL);

        $this->assertNull($response->json('last_message_at'));
    }

    /** @test */
    public function returns_200_when_recent_chat_under_thresholds(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $this->addMessage($chat, $user, 'user', now()->subHours(2));
        $this->addMessage($chat, $user, 'assistant', now()->subHours(1));

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertOk()
            ->assertJsonPath('id', $chat->id)
            ->assertJsonPath('user_message_count', 1)
            ->assertJsonPath('is_active_window', true);
    }

    /** @test */
    public function returns_most_recent_of_multiple_active_chats(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        // Older active chat — last msg 5h ago.
        $older = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $older->created_at = now()->subDays(1);
        $older->save();
        $this->addMessage($older, $user, 'user', now()->subHours(5));

        // Newer active chat — last msg 30m ago. Must win.
        $newer = $this->makeChat($user, $company, Chat::SCOPE_GENERAL);
        $newer->created_at = now()->subHours(2);
        $newer->save();
        $this->addMessage($newer, $user, 'user', now()->subMinutes(30));

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertOk()->assertJsonPath('id', $newer->id);
    }

    /** @test */
    public function returns_403_for_report_not_in_active_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        $user      = $this->makeUser($companyA, 'admin');
        $otherUser = $this->makeUser($companyB, 'admin');
        $foreignReport = $this->makeReport($companyB, $otherUser);

        $response = $this->actingAs($user)
            ->getJson("/api/chats/resume?scope_type=report&report_id={$foreignReport->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function returns_403_for_nonexistent_report(): void
    {
        // A missing report id is treated as forbidden (not 404) so the endpoint
        // never leaks whether a given report id exists in another company.
        // Report model has no SoftDeletes, so a hard-deleted / never-existed
        // report id is the only "gone" case to cover here.
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)
            ->getJson('/api/chats/resume?scope_type=report&report_id=999999');

        $response->assertStatus(403);
    }

    /** @test */
    public function returns_422_without_scope_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume');

        $response->assertStatus(422);
    }

    /** @test */
    public function returns_422_for_scope_type_report_without_report_id(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=report');

        $response->assertStatus(422);
    }

    /** @test */
    public function returns_422_for_invalid_scope_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=bogus');

        $response->assertStatus(422);
    }

    /** @test */
    public function does_not_return_other_users_chats(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $other   = $this->makeUser($company);

        $this->makeChat($other, $company, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        // No chat owned by $user — must be 204 even though another user's chat
        // would qualify.
        $response->assertNoContent();
    }

    /** @test */
    public function does_not_return_chats_from_other_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        // Multi-company user whose home is A; chat created when active was B.
        $user = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [
                ['company_id' => $companyA->id, 'role' => 'admin'],
                ['company_id' => $companyB->id, 'role' => 'admin'],
            ],
        ]);

        // Chat anchored to company B — resume scoped to active company A must skip it.
        $this->makeChat($user, $companyB, Chat::SCOPE_GENERAL);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertNoContent();
    }

    /** @test */
    public function does_not_return_chats_from_other_scope(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        // Only a report-scope chat exists.
        $this->makeChat($user, $company, Chat::SCOPE_REPORT, $report->id);

        // Resume asks for general scope — no match.
        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        $response->assertNoContent();
    }

    /** @test */
    public function does_not_return_report_chat_for_other_report(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $reportA = $this->makeReport($company, $user);
        $reportB = $this->makeReport($company, $user);

        $this->makeChat($user, $company, Chat::SCOPE_REPORT, $reportA->id);

        $response = $this->actingAs($user)
            ->getJson("/api/chats/resume?scope_type=report&report_id={$reportB->id}");

        // Chat is bound to reportA; asking about reportB → nothing to resume.
        $response->assertNoContent();
    }

    /** @test */
    public function eager_loads_messages_and_report_in_response(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        $chat = $this->makeChat($user, $company, Chat::SCOPE_REPORT, $report->id);
        $this->addMessage($chat, $user, 'user', now()->subMinutes(10));
        $this->addMessage($chat, $user, 'assistant', now()->subMinutes(5));

        $response = $this->actingAs($user)
            ->getJson("/api/chats/resume?scope_type=report&report_id={$report->id}");

        $response->assertOk()
            ->assertJsonPath('id', $chat->id)
            ->assertJsonPath('report.id', $report->id);

        // messages list must be present with both rows, ordered by created_at.
        $messages = $response->json('messages');
        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
    }

    /** @test */
    public function general_scope_ignores_chats_with_report_id_set(): void
    {
        // Defensive: a chat with scope_type=general but report_id populated
        // shouldn't surface for a general resume. The endpoint enforces
        // report_id IS NULL for general scope.
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        // Manually construct an odd chat with general scope but a stale report_id.
        $oddChat = $this->makeChat($user, $company, Chat::SCOPE_GENERAL, $report->id);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=general');

        // Should be 204 — the only chat that exists has a non-null report_id.
        $response->assertNoContent();
        // sanity
        $this->assertNotNull($oddChat->fresh()->report_id);
    }
}
