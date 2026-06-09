<?php

declare(strict_types=1);

namespace Tests\Feature\Chats;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Feature tests for the widget_generation chat type and scope=dashboard
 * mini-chat through the inline-create endpoint (POST /api/chats/messages),
 * plus the index/resume scope_type=dashboard plumbing.
 *
 * AI execution is faked via Bus::fake — we only assert the HTTP contract and
 * the persisted Chat shape (type, scope_type, anchors). The WidgetTool
 * create/update + dry-run behaviour is covered directly in WidgetToolDryRunTest.
 */
class ChatWidgetGenerationTest extends TestCase
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

    private function makeUser(Company $home, string $role = 'analyst'): User
    {
        return User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $home->id, 'role' => $role]],
        ]);
    }

    private function makeDashboard(Company $company, User $author): Dashboard
    {
        return Dashboard::create([
            'name'         => ['ru' => 'Дашборд', 'en' => 'Dashboard'],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $author->id,
            'company_id'   => $company->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // inline create: widget_generation
    // -------------------------------------------------------------------------

    /** @test */
    public function inline_create_widget_generation_chat_dispatches_job(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'widget_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Сделай виджет: выручка по менеджерам столбиками',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.type', 'widget_generation')
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_GENERAL);

        $chatId = $response->json('chat.id');
        $this->assertDatabaseHas('chats', [
            'id'         => $chatId,
            'type'       => 'widget_generation',
            'company_id' => $company->id,
        ]);

        Bus::assertDispatched(ProcessChatMessageJob::class);
    }

    /** @test */
    public function inline_create_widget_generation_with_widget_id_pins_existing_widget(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'widget_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'widget_id'  => $widget->id,
            'content'    => 'Поменяй чарт на pie',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.widget_id', $widget->id);
    }

    /** @test */
    public function inline_create_widget_generation_rejects_widget_from_other_company(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $user     = $this->makeUser($companyA);
        $foreign  = Widget::factory()->create(['company_id' => $companyB->id, 'user_id' => null, 'is_system' => true]);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'widget_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'widget_id'  => $foreign->id,
            'content'    => 'edit it',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Chat::count(), 'no chat should be created when the widget is foreign');
    }

    // -------------------------------------------------------------------------
    // inline create: scope=dashboard quick_qa
    // -------------------------------------------------------------------------

    /** @test */
    public function inline_create_dashboard_scope_chat_dispatches_job(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company   = $this->makeCompany('A');
        $user      = $this->makeUser($company);
        $dashboard = $this->makeDashboard($company, $user);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $dashboard->id,
            'content'      => 'Какой виджет показывает больше всего?',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_DASHBOARD)
            ->assertJsonPath('chat.dashboard_id', $dashboard->id)
            // No explicit type → defaults to quick_qa.
            ->assertJsonPath('chat.type', 'quick_qa');
    }

    /** @test */
    public function inline_create_dashboard_scope_requires_dashboard_id(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_DASHBOARD,
            'content'    => 'no dashboard id',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dashboard_id']);
    }

    /** @test */
    public function inline_create_dashboard_scope_rejects_dashboard_from_other_company(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $user     = $this->makeUser($companyA);
        $foreign  = $this->makeDashboard($companyB, $this->makeUser($companyB));

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $foreign->id,
            'content'      => 'q',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Chat::count());
    }

    // -------------------------------------------------------------------------
    // validation
    // -------------------------------------------------------------------------

    /** @test */
    public function inline_create_rejects_unknown_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'dashboard_generation', // not a valid type
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'x',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    /** @test */
    public function inline_create_rejects_unknown_scope_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => 'widget', // not a valid scope
            'content'    => 'x',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['scope_type']);
    }

    // -------------------------------------------------------------------------
    // index / resume: scope=dashboard
    // -------------------------------------------------------------------------

    /** @test */
    public function index_filters_chats_by_dashboard_scope(): void
    {
        $company   = $this->makeCompany('A');
        $user      = $this->makeUser($company);
        $dashboard = $this->makeDashboard($company, $user);

        $dashChat = Chat::create([
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'type'         => 'quick_qa',
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $dashboard->id,
        ]);
        // A general chat that must NOT appear under the dashboard scope.
        Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'quick_qa',
            'scope_type' => Chat::SCOPE_GENERAL,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/chats?scope_type=dashboard&dashboard_id=' . $dashboard->id
        );

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($dashChat->id, $ids);
        $this->assertCount(1, $ids, 'only the dashboard-scoped chat should match');
    }

    /** @test */
    public function resume_returns_dashboard_scoped_chat(): void
    {
        $company   = $this->makeCompany('A');
        $user      = $this->makeUser($company);
        $dashboard = $this->makeDashboard($company, $user);

        $chat = Chat::create([
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'type'         => 'quick_qa',
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $dashboard->id,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/chats/resume?scope_type=dashboard&dashboard_id=' . $dashboard->id
        );

        $response->assertOk()
            ->assertJsonPath('id', $chat->id)
            ->assertJsonPath('scope_type', Chat::SCOPE_DASHBOARD)
            ->assertJsonPath('dashboard_id', $dashboard->id);
    }

    /** @test */
    public function resume_dashboard_scope_requires_dashboard_id(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=dashboard');

        $response->assertStatus(422)->assertJsonValidationErrors(['dashboard_id']);
    }
}
