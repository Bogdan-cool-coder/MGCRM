<?php

declare(strict_types=1);

namespace Tests\Feature\Chats;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Feature tests for the document_template chat type and scope=document mini-chat
 * through the inline-create endpoint (POST /api/chats/messages), plus the
 * index/resume scope_type=document plumbing.
 *
 * AI execution is faked via Bus::fake — we only assert the HTTP contract and the
 * persisted Chat shape (type, scope_type, document anchor). The DocumentTool
 * create/propose + dry-run behaviour is covered in DocumentToolTest.
 */
class ChatDocumentTemplateTest extends TestCase
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

    private function makeTemplate(Company $company, ?User $author, string $type = 'docx'): DocumentTemplate
    {
        return DocumentTemplate::create([
            'name'         => ['ru' => 'Шаблон', 'en' => 'Template'],
            'type'         => $type,
            'config'       => $type === 'html' ? ['html' => '<p>{{complex_name}}</p>'] : [],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $author?->id,
            'company_id'   => $company->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // inline create: document_template
    // -------------------------------------------------------------------------

    /** @test */
    public function inline_create_document_template_chat_dispatches_job(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'document_template',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Собери КП на однокомнатную квартиру',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.type', 'document_template')
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_GENERAL);

        $chatId = $response->json('chat.id');
        $this->assertDatabaseHas('chats', [
            'id'         => $chatId,
            'type'       => 'document_template',
            'company_id' => $company->id,
        ]);

        Bus::assertDispatched(ProcessChatMessageJob::class);
    }

    /** @test */
    public function inline_create_document_scope_pins_existing_template(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company  = $this->makeCompany('A');
        $user     = $this->makeUser($company);
        $template = $this->makeTemplate($company, $user);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'        => 'document_template',
            'scope_type'  => Chat::SCOPE_DOCUMENT,
            'document_id' => $template->id,
            'content'     => 'Какие плейсхолдеры куда мапить?',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_DOCUMENT)
            ->assertJsonPath('chat.document_id', $template->id);
    }

    /** @test */
    public function inline_create_document_scope_requires_document_id(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_DOCUMENT,
            'content'    => 'no document id',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['document_id']);
    }

    /** @test */
    public function inline_create_document_scope_rejects_template_from_other_company(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $user     = $this->makeUser($companyA);
        $foreign  = $this->makeTemplate($companyB, $this->makeUser($companyB));

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'        => 'document_template',
            'scope_type'  => Chat::SCOPE_DOCUMENT,
            'document_id' => $foreign->id,
            'content'     => 'edit it',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Chat::count(), 'no chat should be created when the template is foreign');
    }

    // -------------------------------------------------------------------------
    // validation (store + inline create)
    // -------------------------------------------------------------------------

    /** @test */
    public function store_accepts_document_template_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats', [
            'type'       => 'document_template',
            'scope_type' => Chat::SCOPE_DOCUMENT,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('type', 'document_template')
            ->assertJsonPath('scope_type', Chat::SCOPE_DOCUMENT);
    }

    /** @test */
    public function inline_create_still_rejects_unknown_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'pdf_generation', // not a valid type
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'x',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    /** @test */
    public function inline_create_still_rejects_unknown_scope_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => 'pdf', // not a valid scope
            'content'    => 'x',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['scope_type']);
    }

    // -------------------------------------------------------------------------
    // index / resume: scope=document
    // -------------------------------------------------------------------------

    /** @test */
    public function index_filters_chats_by_document_scope(): void
    {
        $company  = $this->makeCompany('A');
        $user     = $this->makeUser($company);
        $template = $this->makeTemplate($company, $user);

        $docChat = Chat::create([
            'user_id'     => $user->id,
            'company_id'  => $company->id,
            'type'        => 'document_template',
            'scope_type'  => Chat::SCOPE_DOCUMENT,
            'document_id' => $template->id,
        ]);
        // A general chat that must NOT appear under the document scope.
        Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'quick_qa',
            'scope_type' => Chat::SCOPE_GENERAL,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/chats?scope_type=document&document_id=' . $template->id
        );

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($docChat->id, $ids);
        $this->assertCount(1, $ids, 'only the document-scoped chat should match');
    }

    /** @test */
    public function resume_returns_document_scoped_chat(): void
    {
        $company  = $this->makeCompany('A');
        $user     = $this->makeUser($company);
        $template = $this->makeTemplate($company, $user);

        $chat = Chat::create([
            'user_id'     => $user->id,
            'company_id'  => $company->id,
            'type'        => 'document_template',
            'scope_type'  => Chat::SCOPE_DOCUMENT,
            'document_id' => $template->id,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/chats/resume?scope_type=document&document_id=' . $template->id
        );

        $response->assertOk()
            ->assertJsonPath('id', $chat->id)
            ->assertJsonPath('scope_type', Chat::SCOPE_DOCUMENT)
            ->assertJsonPath('document_id', $template->id);
    }

    /** @test */
    public function resume_document_scope_requires_document_id(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->getJson('/api/chats/resume?scope_type=document');

        $response->assertStatus(422)->assertJsonValidationErrors(['document_id']);
    }
}
