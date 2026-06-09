<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\AI\ChatEventEmitter;
use App\Services\AI\DocumentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the document_template AI toolset:
 *   - propose_document_fields validates suggested fields (field-catalog key OR
 *     real MacroData model field), numbers nothing but lists survivors, drops
 *     invalid into `rejected`, emits a document_fields_proposed event, and does
 *     NOT persist anything.
 *   - generate_document_template creates an html DocumentTemplate, pins
 *     chat.document_id, runs a dry-run via HtmlDocumentService, and on a broken
 *     config tags metadata.dry_run_failed + escalates the semantic-retry hint.
 *
 * HtmlDocumentService is a real binding (it never touches MacroData or the DB),
 * so the dry-run runs for real against the generated config.html. No MySQL.
 */
class DocumentToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.dry_run.enabled' => true]);
        config(['ai.dry_run.max_semantic_retries' => 2]);
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'DocCo']);
        $user = User::forceCreate([
            'name'       => 'Tester',
            'email'      => 'doc+' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
            'locale'     => 'ru',
        ]);

        return Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'document_template',
            'scope_type' => Chat::SCOPE_DOCUMENT,
        ]);
    }

    private function invokeTool(DocumentTool $tool, Chat $chat, string $toolName, array $args, ?object $dryRunState = null, ?ChatEventEmitter $emitter = null): string
    {
        foreach ($tool->getTools($chat, $dryRunState, $emitter) as $t) {
            if ($t->name() === $toolName) {
                return $t->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    // -------------------------------------------------------------------------
    // tool registration
    // -------------------------------------------------------------------------

    public function test_document_toolset_registers_probe_propose_and_generate(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $names = array_map(fn ($t) => $t->name(), $tool->getTools($chat));

        $this->assertContains('probe_data', $names);
        $this->assertContains('propose_document_fields', $names);
        $this->assertContains('generate_document_template', $names);
    }

    // -------------------------------------------------------------------------
    // propose_document_fields
    // -------------------------------------------------------------------------

    public function test_propose_document_fields_accepts_catalog_keys_and_does_not_persist(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $placeholders = json_encode([
            ['token' => 'complex',        'suggested_field' => 'estate.complex_name', 'confidence' => 0.9],
            ['token' => 'price',          'suggested_field' => 'estate.price', 'confidence' => 0.85],
            ['token' => 'inn',            'suggested_field' => 'req_inn', 'confidence' => 0.7],
            ['token' => 'header',         'suggested_field' => 'brand_header'],
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders]), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue($result['proposed'] ?? false);
        $this->assertSame(4, $result['placeholders_count']);
        $this->assertSame('estate.complex_name', $result['placeholders'][0]['suggested_field']);
        $this->assertSame('catalog', $result['placeholders'][0]['source']);
        // req_inn resolves via the req_* wildcard.
        $this->assertSame('catalog', $result['placeholders'][2]['source']);

        // Nothing persisted, chat not pinned.
        $this->assertSame(0, DocumentTemplate::count());
        $chat->refresh();
        $this->assertNull($chat->document_id);
    }

    public function test_propose_document_fields_strips_dollar_braces_from_token(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $placeholders = json_encode([
            ['token' => '${complex_name}', 'suggested_field' => 'estate.complex_name'],
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders]), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame('complex_name', $result['placeholders'][0]['token']);
    }

    public function test_propose_document_fields_drops_invalid_keeps_valid(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $placeholders = json_encode([
            ['token' => 'ok',     'suggested_field' => 'estate.area'],
            // Not a catalog key, no model → rejected.
            ['token' => 'bogus',  'suggested_field' => 'totally_made_up_field'],
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders]), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame(1, $result['placeholders_count']);
        $this->assertArrayHasKey('rejected', $result);
        $this->assertSame('bogus', $result['rejected'][0]['token']);
    }

    public function test_propose_document_fields_validates_real_macrodata_model_field(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        // EstateDeals.ipoteka_rate is a real model field (declared in casts) that
        // is NOT a field-catalog key, so it resolves via the macrodata path; a
        // garbage field on a real model is rejected.
        $placeholders = json_encode([
            ['token' => 'rate',  'suggested_field' => 'ipoteka_rate', 'model' => 'EstateDeals'],
            ['token' => 'nope',  'suggested_field' => 'no_such_column_xyz', 'model' => 'EstateDeals'],
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders]), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame(1, $result['placeholders_count']);
        $this->assertSame('macrodata', $result['placeholders'][0]['source']);
        $this->assertArrayHasKey('rejected', $result);
    }

    public function test_propose_document_fields_rejects_unknown_model(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $placeholders = json_encode([
            ['token' => 'x', 'suggested_field' => 'some_field', 'model' => 'NoSuchModel'],
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders]), true);

        $this->assertFalse($result['success'] ?? true, json_encode($result));
        $this->assertArrayHasKey('rejected', $result);
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_propose_document_fields_rejects_non_array_payload(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $result = json_decode($this->invokeTool($tool, $chat, 'propose_document_fields', ['{"not":"an array"}']), true);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('array', $result['error']);
    }

    public function test_propose_document_fields_emits_event(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $assistant = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => '',
            'status'     => ChatMessage::STATUS_RUNNING,
        ]);
        $emitter = new ChatEventEmitter($assistant->id);

        $placeholders = json_encode([
            ['token' => 'complex', 'suggested_field' => 'estate.complex_name'],
            ['token' => 'price',   'suggested_field' => 'estate.price'],
        ]);

        $this->invokeTool($tool, $chat, 'propose_document_fields', [$placeholders], null, $emitter);

        $event = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->where('type', ChatMessageEvent::TYPE_DOCUMENT_FIELDS_PROPOSED)
            ->first();

        $this->assertNotNull($event, 'a document_fields_proposed event must be emitted');
        $this->assertCount(2, $event->payload['placeholders']);
        $this->assertSame('estate.complex_name', $event->payload['placeholders'][0]['suggested_field']);
    }

    // -------------------------------------------------------------------------
    // generate_document_template
    // -------------------------------------------------------------------------

    public function test_generate_document_template_creates_html_template_and_pins_chat(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $name = json_encode(['ru' => 'КП на квартиру', 'en' => 'Flat proposal']);
        $config = json_encode([
            'html' => '<section><h1>${estate.complex_name}, кв. ${estate.number}</h1><p>Площадь: ${estate.area|format} м²</p><p>Цена: ${estate.price|format} ₽ (${estate.price|words})</p></section>',
            'css'  => 'section { padding: 24px; }',
        ]);

        $result = json_decode($this->invokeTool($tool, $chat, 'generate_document_template', [$name, $config]), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue($result['created'] ?? false);

        $template = DocumentTemplate::find($result['document_id']);
        $this->assertNotNull($template);
        $this->assertSame('html', $template->type);
        $this->assertFalse($template->is_system);
        $this->assertSame($chat->company_id, $template->company_id);
        $this->assertSame($chat->user_id, $template->user_id);

        $chat->refresh();
        $this->assertSame($template->id, $chat->document_id);

        // Dry-run produced a non-empty render preview.
        $this->assertArrayHasKey('preview', $result);
        $this->assertGreaterThan(0, $result['preview']['html_length']);
    }

    public function test_generate_document_template_rejects_missing_html(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        $name = json_encode(['ru' => 'Пустой', 'en' => 'Empty']);
        $config = json_encode(['css' => 'h1 { color: red; }']); // no html

        $result = json_decode($this->invokeTool($tool, $chat, 'generate_document_template', [$name, $config]), true);

        $this->assertFalse($result['success'] ?? true, json_encode($result));
        $this->assertSame('missing_html', $result['errors'][0]['type']);
        // Pre-validation rejects before any row is written.
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_generate_document_template_updates_pinned_template(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(DocumentTool::class);

        // First create.
        $name1 = json_encode(['ru' => 'v1', 'en' => 'v1']);
        $config1 = json_encode(['html' => '<p>${estate.complex_name}</p>']);
        $first = json_decode($this->invokeTool($tool, $chat, 'generate_document_template', [$name1, $config1]), true);
        $docId = $first['document_id'];

        // Second call on the same (now-pinned) chat updates, not creates.
        $name2 = json_encode(['ru' => 'v2', 'en' => 'v2']);
        $config2 = json_encode(['html' => '<h1>${estate.complex_name}</h1><p>${estate.price|format}</p>']);
        $second = json_decode($this->invokeTool($tool, $chat->fresh(), 'generate_document_template', [$name2, $config2]), true);

        $this->assertTrue($second['success'] ?? false, json_encode($second));
        $this->assertTrue($second['updated'] ?? false);
        $this->assertSame($docId, $second['document_id']);
        $this->assertSame(1, DocumentTemplate::count(), 'second call must update, not create a second row');

        $template = DocumentTemplate::find($docId);
        $this->assertStringContainsString('estate.price', $template->config['html']);
    }
}
