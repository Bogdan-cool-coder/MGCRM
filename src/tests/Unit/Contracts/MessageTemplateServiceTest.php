<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Contracts\Services\MessageTemplateService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessageTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MessageTemplateService::class);
    }

    // =========================================================================
    // Render
    // =========================================================================

    public function test_render_replaces_known_keys(): void
    {
        $template = MessageTemplate::factory()->create([
            'body' => 'Привет, {{contact.full_name}}! Сделка: {{deal.name}}.',
        ]);

        $result = $this->service->render($template, [
            'contact.full_name' => 'Иван Иванов',
            'deal.name' => 'ООО Ромашка',
        ]);

        $this->assertSame('Привет, Иван Иванов! Сделка: ООО Ромашка.', $result['body']);
        $this->assertEmpty($result['unresolved_keys']);
    }

    public function test_render_leaves_unknown_keys_intact(): void
    {
        $template = MessageTemplate::factory()->create([
            'body' => 'Сделка: {{deal.name}}. Сумма: {{deal.amount}}.',
        ]);

        $result = $this->service->render($template, [
            'deal.name' => 'ООО Ромашка',
        ]);

        $this->assertStringContainsString('{{deal.amount}}', $result['body']);
        $this->assertSame('Сделка: ООО Ромашка. Сумма: {{deal.amount}}.', $result['body']);
    }

    public function test_render_reports_unresolved_keys(): void
    {
        $template = MessageTemplate::factory()->create([
            'body' => '{{deal.name}} — {{foo.bar}} — {{baz.qux}}',
        ]);

        $result = $this->service->render($template, ['deal.name' => 'X']);

        $this->assertEqualsCanonicalizing(['foo.bar', 'baz.qux'], $result['unresolved_keys']);
    }

    public function test_render_subject_also_rendered(): void
    {
        $template = MessageTemplate::factory()->create([
            'subject' => 'Договор №{{document.number}}',
            'body' => 'Тело',
        ]);

        $result = $this->service->render($template, ['document.number' => 'TAS-220/UZ']);

        $this->assertSame('Договор №TAS-220/UZ', $result['subject']);
    }

    public function test_render_empty_body_returns_empty(): void
    {
        $template = MessageTemplate::factory()->create(['body' => '']);

        $result = $this->service->render($template, []);

        $this->assertSame('', $result['body']);
        $this->assertEmpty($result['unresolved_keys']);
    }

    public function test_render_multiple_same_key(): void
    {
        $template = MessageTemplate::factory()->create([
            'body' => '{{deal.name}} и ещё раз {{deal.name}}',
        ]);

        $result = $this->service->render($template, ['deal.name' => 'Альфа']);

        $this->assertSame('Альфа и ещё раз Альфа', $result['body']);
    }

    // =========================================================================
    // BuildVars
    // =========================================================================

    public function test_build_vars_from_deal_and_company(): void
    {
        $company = Company::factory()->create(['name' => 'ООО Тест', 'tax_id' => '123456789']);
        $deal = Deal::factory()->create(['title' => 'Тест-сделка', 'amount' => 100000]);

        $vars = $this->service->buildVars(['deal' => $deal, 'company' => $company]);

        $this->assertSame('Тест-сделка', $vars['deal.name']);
        $this->assertSame('ООО Тест', $vars['company.name']);
        $this->assertArrayHasKey('date.today', $vars);
    }

    public function test_build_vars_null_model_skips_keys(): void
    {
        $vars = $this->service->buildVars(['company' => null]);

        $this->assertArrayNotHasKey('company.name', $vars);
        $this->assertArrayNotHasKey('company.inn', $vars);
        $this->assertArrayNotHasKey('company.city', $vars);
    }

    public function test_build_vars_includes_date_keys(): void
    {
        $vars = $this->service->buildVars([]);

        $this->assertArrayHasKey('date.today', $vars);
        $this->assertArrayHasKey('date.tomorrow', $vars);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', $vars['date.today']);
    }

    public function test_build_vars_formats_money(): void
    {
        $deal = Deal::factory()->create(['amount' => 1234567, 'currency' => 'RUB']);

        $vars = $this->service->buildVars(['deal' => $deal]);

        // 1234567 kopecks = 12345,67 → formatted
        $this->assertStringContainsString('345', $vars['deal.amount']);
        $this->assertStringContainsString(',', $vars['deal.amount']);
    }

    public function test_build_vars_contact_fields(): void
    {
        $contact = Contact::factory()->create([
            'full_name' => 'Иван Петров',
            'phone' => '+79001234567',
            'email' => 'ivan@example.com',
        ]);

        $vars = $this->service->buildVars(['contact' => $contact]);

        $this->assertSame('Иван Петров', $vars['contact.full_name']);
        $this->assertSame('+79001234567', $vars['contact.phone']);
        $this->assertSame('ivan@example.com', $vars['contact.email']);
    }

    public function test_build_vars_user_full_name(): void
    {
        $user = User::factory()->create(['full_name' => 'Менеджер Васин']);

        $vars = $this->service->buildVars(['user' => $user]);

        $this->assertSame('Менеджер Васин', $vars['user.full_name']);
    }

    // =========================================================================
    // FindForContext
    // =========================================================================

    public function test_find_returns_most_specific_binding(): void
    {
        // Generic: only channel_kind=tg (score=1)
        $generic = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $generic->id,
            'channel_kind' => 'tg',
        ]);

        // Specific: channel_kind=tg + activity_type=call (score=2)
        $specific = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $specific->id,
            'channel_kind' => 'tg',
            'activity_type' => 'call',
        ]);

        $found = $this->service->findForContext([
            'channel_kind' => ChannelKind::Tg,
            'activity_type' => ActivityType::Call,
        ]);

        $this->assertNotNull($found);
        $this->assertSame($specific->id, $found->id);
    }

    public function test_find_wildcard_binding_matches_any_context(): void
    {
        $template = MessageTemplate::factory()->create();
        // Wildcard binding: all null
        MessageTemplateBinding::factory()->wildcard()->create([
            'message_template_id' => $template->id,
        ]);

        $found = $this->service->findForContext(['channel_kind' => 'email']);

        $this->assertNotNull($found);
        $this->assertSame($template->id, $found->id);
    }

    public function test_find_null_when_no_active_templates(): void
    {
        $found = $this->service->findForContext(['channel_kind' => 'tg']);

        $this->assertNull($found);
    }

    public function test_find_inactive_template_not_returned(): void
    {
        $template = MessageTemplate::factory()->inactive()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $template->id,
            'channel_kind' => 'tg',
        ]);

        $found = $this->service->findForContext(['channel_kind' => 'tg']);

        $this->assertNull($found);
    }

    public function test_find_by_channel_kind_exact(): void
    {
        $template = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $template->id,
            'channel_kind' => 'tg',
        ]);

        // email binding should NOT match
        $other = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $other->id,
            'channel_kind' => 'email',
        ]);

        $found = $this->service->findForContext(['channel_kind' => 'tg']);

        $this->assertSame($template->id, $found?->id);
    }

    public function test_find_by_pipeline_stage_id(): void
    {
        // Use a real pipeline/stage to satisfy FK constraints in SQLite
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $template = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $template->id,
            'pipeline_stage_id' => $stage->id,
        ]);

        $found = $this->service->findForContext(['pipeline_stage_id' => $stage->id]);

        $this->assertSame($template->id, $found?->id);
    }

    public function test_find_by_automation_slot(): void
    {
        $template = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $template->id,
            'automation_slot' => 'welcome_new_lead',
        ]);

        $found = $this->service->findForContext(['automation_slot' => 'welcome_new_lead']);

        $this->assertSame($template->id, $found?->id);
    }

    public function test_find_prefers_stage_over_pipeline_only(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        // pipeline_id only: score=1
        $pipelineOnly = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $pipelineOnly->id,
            'pipeline_id' => $pipeline->id,
        ]);

        // pipeline_id + pipeline_stage_id: score=2
        $stageSpecific = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $stageSpecific->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
        ]);

        $found = $this->service->findForContext([
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
        ]);

        $this->assertSame($stageSpecific->id, $found?->id);
    }

    public function test_find_equal_score_returns_lowest_id(): void
    {
        // Two templates, same score=1 (both tg only), lower id wins
        $t1 = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $t1->id,
            'channel_kind' => 'tg',
        ]);

        $t2 = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $t2->id,
            'channel_kind' => 'tg',
        ]);

        $found = $this->service->findForContext(['channel_kind' => 'tg']);

        $this->assertSame(min($t1->id, $t2->id), $found?->id);
    }
}
