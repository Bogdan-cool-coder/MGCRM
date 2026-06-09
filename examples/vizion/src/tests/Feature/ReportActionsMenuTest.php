<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the report-actions menu backend:
 *   - POST /api/reports/{id}/publish    — admin/superadmin toggle is_published
 *   - POST /api/reports/{id}/unpublish  — inverse
 *   - DELETE /api/reports/{id}          — cascades to originating Chat
 *   - GET   /api/reports + /api/reports/{id} expose created_at + author
 *
 * Pairs with ReportActiveCompanyScopingTest, which already covers the broader
 * active-company ACL surface. Here we focus on the publish-flag toggle, the
 * cascade semantics, and the audit-metadata projection.
 */
class ReportActionsMenuTest extends TestCase
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

    private function makeReport(int $companyId, ?int $userId, array $overrides = []): Report
    {
        return Report::create(array_merge([
            'title'        => ['ru' => 'Отчёт', 'en' => 'Report'],
            'description'  => null,
            'config'       => ['model' => 'Deals', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $userId,
            'company_id'   => $companyId,
        ], $overrides));
    }

    /**
     * Stub ReportDataService so show() can run without a real MacroData DB.
     * Returns the same minimal canned shape used in the broader scoping suite.
     */
    private function stubReportDataService(): void
    {
        $this->instance(
            ReportDataService::class,
            \Mockery::mock(ReportDataService::class, function ($mock) {
                $mock->shouldReceive('getData')->andReturn([
                    'id'                => 0,
                    'title'             => ['ru' => 'Отчёт', 'en' => 'Report'],
                    'description'       => null,
                    'columns'           => [],
                    'rows'              => [],
                    'meta'              => ['total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1],
                    'filters_available' => [],
                    'filters_applied'   => [],
                    'totals'            => [],
                    'config'            => [],
                ]);
            })
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/reports/{id}/publish
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_publish_report_in_active_company(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/api/reports/{$report->id}/publish");

        $response->assertOk();
        $response->assertJsonPath('is_published', true);
        $this->assertTrue($report->fresh()->is_published);
    }

    /** @test */
    public function test_superadmin_can_publish_any_company_report(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');

        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $other->id,
            'role'              => 'superadmin',
            'company_accesses'  => [
                ['company_id' => $home->id,  'role' => 'superadmin'],
                ['company_id' => $other->id, 'role' => 'superadmin'],
            ],
        ]);
        $report = $this->makeReport($other->id, $superadmin->id);

        $response = $this->actingAs($superadmin)
            ->postJson("/api/reports/{$report->id}/publish");

        $response->assertOk();
        $this->assertTrue($report->fresh()->is_published);
    }

    /** @test */
    public function test_analyst_cannot_publish_even_own_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'analyst']],
        ]);
        $report = $this->makeReport($company->id, $analyst->id);

        $response = $this->actingAs($analyst)
            ->postJson("/api/reports/{$report->id}/publish");

        $response->assertStatus(403);
        $this->assertFalse($report->fresh()->is_published);
    }

    /** @test */
    public function test_viewer_cannot_publish(): void
    {
        $company = $this->makeCompany('CompanyA');
        $viewer = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'viewer',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'viewer']],
        ]);
        $author = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);
        $report = $this->makeReport($company->id, $author->id);

        $response = $this->actingAs($viewer)
            ->postJson("/api/reports/{$report->id}/publish");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_publish_rejects_system_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $superadmin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
        $systemReport = $this->makeReport($company->id, null, ['is_system' => true]);

        $response = $this->actingAs($superadmin)
            ->postJson("/api/reports/{$systemReport->id}/publish");

        $response->assertStatus(403);
        // is_published of a system report stays untouched (default false).
        $this->assertFalse($systemReport->fresh()->is_published);
    }

    /** @test */
    public function test_admin_cannot_publish_report_from_another_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        // Admin is in/active on A but tries to publish a report living in B.
        $admin = User::factory()->create([
            'company_id'        => $companyA->id,
            'active_company_id' => $companyA->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $companyA->id, 'role' => 'admin']],
        ]);
        $reportInB = $this->makeReport($companyB->id, null);

        $response = $this->actingAs($admin)
            ->postJson("/api/reports/{$reportInB->id}/publish");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // POST /api/reports/{id}/unpublish
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_unpublish_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id, ['is_published' => true]);

        $response = $this->actingAs($admin)
            ->postJson("/api/reports/{$report->id}/unpublish");

        $response->assertOk();
        $response->assertJsonPath('is_published', false);
        $this->assertFalse($report->fresh()->is_published);
    }

    /** @test */
    public function test_analyst_cannot_unpublish(): void
    {
        $company = $this->makeCompany('CompanyA');
        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'analyst']],
        ]);
        $report = $this->makeReport($company->id, $analyst->id, ['is_published' => true]);

        $response = $this->actingAs($analyst)
            ->postJson("/api/reports/{$report->id}/unpublish");

        $response->assertStatus(403);
        $this->assertTrue($report->fresh()->is_published);
    }

    /** @test */
    public function test_viewer_cannot_unpublish(): void
    {
        $company = $this->makeCompany('CompanyA');
        $viewer = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'viewer',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'viewer']],
        ]);
        // Author is someone else — a viewer never has publish rights even on a
        // report it can read.
        $author = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);
        $report = $this->makeReport($company->id, $author->id, ['is_published' => true]);

        $response = $this->actingAs($viewer)
            ->postJson("/api/reports/{$report->id}/unpublish");

        $response->assertStatus(403);
        // State untouched — the report stays published.
        $this->assertTrue($report->fresh()->is_published);
    }

    /** @test */
    public function test_unpublish_rejects_system_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $superadmin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
        $systemReport = $this->makeReport($company->id, null, [
            'is_system'    => true,
            // Should never actually be published, but test the guard anyway.
            'is_published' => true,
        ]);

        $response = $this->actingAs($superadmin)
            ->postJson("/api/reports/{$systemReport->id}/unpublish");

        $response->assertStatus(403);
        $this->assertTrue($systemReport->fresh()->is_published);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/reports/{id} — chat cascade
    // -------------------------------------------------------------------------

    /** @test */
    public function test_deleting_report_cascades_to_originating_chat(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        $chat = Chat::create([
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
            'title'      => 'Test chat',
        ]);
        $userMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'role'       => 'user',
            'content'    => 'Build me a report',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $assistantMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => 'Here you go',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $report = $this->makeReport($company->id, $admin->id, [
            'chat_message_id' => $assistantMessage->id,
        ]);
        // Mirror ReportTool::handleSuccess: pin the chat to the report it
        // produced. This is what makes Report::chat() hasOne resolve to $chat
        // and drives the cascade in ReportController::destroy.
        $chat->update(['report_id' => $report->id]);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertOk();
        $this->assertNull(Report::find($report->id));
        $this->assertNull(Chat::find($chat->id), 'originating chat must be deleted');
        $this->assertNull(ChatMessage::find($userMessage->id), 'chat messages must cascade-delete with chat');
        $this->assertNull(ChatMessage::find($assistantMessage->id));
    }

    /** @test */
    public function test_deleting_report_only_touches_its_own_pinned_chat(): void
    {
        // Cascade is now driven by chats.report_id (Report::chat() hasOne),
        // not by chat_messages.chat_id lookups. Because chats.report_id is the
        // FK direction, a single Chat references at most one Report — so the
        // "two reports sharing one chat" scenario the old logic guarded
        // against is impossible by construction. What we DO need to prove is
        // that deleting reportA doesn't accidentally take down chatB (the
        // chat pinned to reportB), which would happen if we cascaded based on
        // anything other than $report->chat.
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        $chatA = Chat::create([
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);
        $messageA = ChatMessage::create([
            'chat_id'    => $chatA->id,
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => 'A',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $reportA = $this->makeReport($company->id, $admin->id, ['chat_message_id' => $messageA->id]);
        $chatA->update(['report_id' => $reportA->id]);

        $chatB = Chat::create([
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);
        $messageB = ChatMessage::create([
            'chat_id'    => $chatB->id,
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => 'B',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $reportB = $this->makeReport($company->id, $admin->id, ['chat_message_id' => $messageB->id]);
        $chatB->update(['report_id' => $reportB->id]);

        // Delete reportA — chatA must go, chatB must survive untouched.
        $response = $this->actingAs($admin)
            ->deleteJson("/api/reports/{$reportA->id}");

        $response->assertOk();
        $this->assertNull(Report::find($reportA->id));
        $this->assertNull(Chat::find($chatA->id), 'chat pinned to deleted report must be removed');
        $this->assertNotNull(Chat::find($chatB->id), 'chat pinned to a different report must NOT be touched');
        $this->assertNotNull(Report::find($reportB->id));

        // Now delete reportB — its chat should also go away.
        $response = $this->actingAs($admin)
            ->deleteJson("/api/reports/{$reportB->id}");

        $response->assertOk();
        $this->assertNull(Chat::find($chatB->id));
    }

    /** @test */
    public function test_deleting_report_without_pinned_chat_skips_chat_cascade(): void
    {
        // Legacy / partial-state case: a report carries chat_message_id but
        // chats.report_id was never pinned (e.g. ReportTool::handleSuccess
        // didn't run, or the chat link was manually cleared). The cascade
        // must be a no-op for the chat — Report::chat hasOne returns null and
        // we simply delete the report.
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);

        $chat = Chat::create([
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
            // Note: no report_id pinned.
        ]);
        $message = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => 'A',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $report = $this->makeReport($company->id, $admin->id, ['chat_message_id' => $message->id]);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertOk();
        $this->assertNull(Report::find($report->id));
        // Chat survives because nothing pinned it to this report.
        $this->assertNotNull(Chat::find($chat->id), 'unpinned chat must not be cascade-deleted');
        $this->assertNotNull(ChatMessage::find($message->id));
    }

    /** @test */
    public function test_deleting_report_without_chat_works_unchanged(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertOk();
        $this->assertNull(Report::find($report->id));
    }

    /** @test */
    public function test_destroy_rejects_system_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $superadmin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
        $systemReport = $this->makeReport($company->id, null, ['is_system' => true]);

        $response = $this->actingAs($superadmin)
            ->deleteJson("/api/reports/{$systemReport->id}");

        $response->assertStatus(403);
        $this->assertNotNull(Report::find($systemReport->id));
    }

    /** @test */
    public function test_analyst_can_delete_own_report_with_chat_cascade(): void
    {
        $company = $this->makeCompany('CompanyA');
        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'analyst']],
        ]);
        $chat = Chat::create([
            'user_id'    => $analyst->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);
        $message = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $analyst->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => 'A',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        $report = $this->makeReport($company->id, $analyst->id, ['chat_message_id' => $message->id]);
        // Mirror ReportTool::handleSuccess: pin the chat to this report so
        // Report::chat hasOne resolves and the destroy cascade fires.
        $chat->update(['report_id' => $report->id]);

        $response = $this->actingAs($analyst)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertOk();
        $this->assertNull(Report::find($report->id));
        $this->assertNull(Chat::find($chat->id));
    }

    /** @test */
    public function test_viewer_cannot_delete_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $viewer = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'viewer',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'viewer']],
        ]);
        // A published report the viewer can read but must never be able to delete.
        $author = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);
        $report = $this->makeReport($company->id, $author->id, ['is_published' => true]);

        $response = $this->actingAs($viewer)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertStatus(403);
        $this->assertNotNull(Report::find($report->id), 'viewer delete must be a no-op');
    }

    /** @test */
    public function test_analyst_cannot_delete_other_users_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $analyst = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'analyst']],
        ]);
        $other = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);
        $report = $this->makeReport($company->id, $other->id);

        $response = $this->actingAs($analyst)
            ->deleteJson("/api/reports/{$report->id}");

        $response->assertStatus(403);
        $this->assertNotNull(Report::find($report->id));
    }

    // -------------------------------------------------------------------------
    // GET /api/reports + /api/reports/{id} — created_at + author
    // -------------------------------------------------------------------------

    /** @test */
    public function test_index_response_includes_created_at_and_author(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'name'              => 'Alice Author',
            'email'             => 'alice@example.com',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $response = $this->actingAs($admin)->getJson('/api/reports');
        $response->assertOk();

        $entry = collect($response->json())->firstWhere('id', $report->id);
        $this->assertNotNull($entry, 'report must be present in the list');
        $this->assertArrayHasKey('created_at', $entry);
        $this->assertNotNull($entry['created_at']);
        $this->assertIsArray($entry['author']);
        $this->assertSame($admin->id, $entry['author']['id']);
        $this->assertSame('Alice Author', $entry['author']['name']);
        $this->assertSame('alice@example.com', $entry['author']['email']);
        // index() serialises the full Eloquent model, so ownership/visibility
        // fields are present implicitly. Asserted here so show() parity is
        // measurable (see test_show_response_includes_created_at_and_author).
        $this->assertArrayHasKey('is_system', $entry);
        $this->assertFalse($entry['is_system']);
        $this->assertArrayHasKey('is_published', $entry);
        $this->assertFalse($entry['is_published']);
    }

    /** @test */
    public function test_index_returns_null_author_for_system_report(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $systemReport = $this->makeReport($company->id, null, ['is_system' => true]);

        $response = $this->actingAs($admin)->getJson('/api/reports');
        $response->assertOk();

        $entry = collect($response->json())->firstWhere('id', $systemReport->id);
        $this->assertNotNull($entry);
        $this->assertNull($entry['author'], 'system report (user_id=null) must serialise author as null');
    }

    /** @test */
    public function test_show_response_includes_created_at_and_author(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'name'              => 'Bob Builder',
            'email'             => 'bob@example.com',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)->getJson("/api/reports/{$report->id}");
        $response->assertOk();
        $response->assertJsonPath('author.id', $admin->id);
        $response->assertJsonPath('author.name', 'Bob Builder');
        $response->assertJsonPath('author.email', 'bob@example.com');
        $this->assertNotNull($response->json('created_at'));
        // Ownership + visibility flags must reach the report page — without
        // these, the frontend treats every report as an editable draft and
        // wrongly shows publish/delete on system reports. See bug verified by
        // QA: GET /api/reports/1 omitted is_system entirely.
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('is_published', false);
        $response->assertJsonPath('user_id', $admin->id);
        $response->assertJsonPath('chat_message_id', null);
        // A report with no pinned chat exposes chat_id: null. The "Edit with AI"
        // affordance on the frontend keys off this — null means no originating
        // chat to resume.
        $response->assertJsonPath('chat_id', null);
    }

    /** @test */
    public function test_show_response_includes_chat_id_when_report_has_pinned_chat(): void
    {
        $company = $this->makeCompany('CompanyA');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $report = $this->makeReport($company->id, $admin->id);
        // Mirror ReportTool::handleSuccess: the report_generation chat that
        // produced this report is pinned via chats.report_id. Report::chat()
        // hasOne resolves to it, and show() must surface chat_id for the
        // "Edit with AI" handoff.
        $chat = Chat::create([
            'user_id'    => $admin->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
            'title'      => 'Originating chat',
            'report_id'  => $report->id,
        ]);

        $this->stubReportDataService();

        $response = $this->actingAs($admin)->getJson("/api/reports/{$report->id}");
        $response->assertOk();
        $response->assertJsonPath('chat_id', $chat->id);
    }

    /** @test */
    public function test_show_response_marks_system_report_correctly(): void
    {
        $company = $this->makeCompany('CompanyA');
        $superadmin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
        $systemReport = $this->makeReport($company->id, null, ['is_system' => true]);

        $this->stubReportDataService();

        $response = $this->actingAs($superadmin)->getJson("/api/reports/{$systemReport->id}");
        $response->assertOk();
        // The whole point of the fix: system reports must self-identify on
        // /show so the frontend can hide draft/publish/delete affordances.
        $response->assertJsonPath('is_system', true);
        $response->assertJsonPath('user_id', null);
        $response->assertJsonPath('author', null);
    }
}
