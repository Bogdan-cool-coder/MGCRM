<?php

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use App\Models\Widget;
use App\Services\AI\ChatEventEmitter;
use App\Services\AI\WidgetTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the two-step widget flow: propose_widget_variants → (user picks) →
 * create_widget with the chosen variant's config.
 *
 * Locks down:
 *   1. propose_widget_variants validates each candidate, numbers the survivors,
 *      and DOES NOT persist any Widget (variants are ephemeral proposals).
 *   2. invalid candidates are dropped (into `rejected`) while valid ones still
 *      come back — the user still gets a choice.
 *   3. all-invalid → success:false with no widget created.
 *   4. a widget_variants event is emitted carrying the numbered variants.
 *   5. the chosen variant's config feeds create_widget verbatim and creates a
 *      real Widget (end-to-end of the selection loop).
 *
 * Mirrors WidgetToolDryRunTest's stubs (ConfigNormalizer / ConnectionService /
 * WidgetDataService) so nothing touches live MySQL.
 */
class WidgetToolProposeVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function stubMap(): array
    {
        return [
            'models'    => ['EstateDeals' => 'EstateDeals', 'estate_deals' => 'EstateDeals'],
            'relations' => ['EstateDeals' => []],
            'related'   => ['EstateDeals' => []],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $stubMap = $this->stubMap();
        $this->app->instance(ConfigNormalizer::class, new class($stubMap) extends ConfigNormalizer {
            public function __construct(private readonly array $stubbedMap) {}

            public function getCanonicalMap(): array
            {
                return $this->stubbedMap;
            }
        });

        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });

        // Happy compute stub so the create_widget step (which DOES dry-run)
        // succeeds. propose_widget_variants never calls compute().
        $this->app->instance(WidgetDataService::class, new class extends WidgetDataService {
            public function __construct() {}

            public function compute(Widget $widget, Company $company, ?string $periodFrom = null, ?string $periodTo = null): array
            {
                return [
                    'labels'   => ['A', 'B'],
                    'datasets' => [['label' => 'value', 'data' => [10, 20]]],
                    'meta'     => ['period_from' => null, 'period_to' => null, 'period_applied' => false, 'row_count' => 2],
                ];
            }
        });

        config(['ai.dry_run.enabled' => true]);
        config(['ai.dry_run.max_semantic_retries' => 2]);
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'WidgetVariantsCo']);
        $user = User::forceCreate([
            'name'       => 'Tester',
            'email'      => 'wpv+' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
            'locale'     => 'ru',
        ]);

        return Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'widget_generation',
        ]);
    }

    private function invokeTool(WidgetTool $tool, Chat $chat, string $toolName, array $args, ?object $dryRunState = null, ?ChatEventEmitter $emitter = null): string
    {
        foreach ($tool->getTools($chat, $dryRunState, $emitter) as $t) {
            if ($t->name() === $toolName) {
                return $t->handle(...$args);
            }
        }

        $this->fail("Tool {$toolName} not registered for chat type {$chat->type}");
    }

    private function variantConfig(string $chartType, string $fn, string $alias): array
    {
        $agg = $fn === 'count'
            ? ['fn' => 'count', 'as' => $alias]
            : ['field' => 'deal_sum', 'fn' => $fn, 'as' => $alias];

        return [
            'primary_model' => 'EstateDeals',
            'group_by'      => ['fields' => ['deal_status']],
            'aggregates'    => [$agg],
            'chart'         => ['type' => $chartType, 'label_field' => 'deal_status', 'value_field' => $alias],
        ];
    }

    // -------------------------------------------------------------------------
    // propose_widget_variants — registered + happy path
    // -------------------------------------------------------------------------

    public function test_propose_widget_variants_tool_is_registered(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $names = array_map(fn ($t) => $t->name(), $tool->getTools($chat));
        $this->assertContains('propose_widget_variants', $names);
    }

    public function test_propose_widget_variants_validates_numbers_and_does_not_persist(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $variants = json_encode([
            ['label' => ['ru' => 'Доли — кольцевая', 'en' => 'Shares — doughnut'], 'config' => $this->variantConfig('doughnut', 'count', 'cnt')],
            ['label' => 'Сравнение — столбцы', 'config' => $this->variantConfig('bar', 'count', 'cnt')],
            ['label' => 'Выручка — столбцы', 'config' => $this->variantConfig('bar', 'sum', 'total')],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'propose_widget_variants', [$variants]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, 'Expected success: ' . $resultJson);
        $this->assertTrue($result['proposed'] ?? false);
        $this->assertSame(3, $result['variants_count']);

        // 1-based numbering preserved + config round-trips.
        $this->assertSame(1, $result['variants'][0]['index']);
        $this->assertSame(2, $result['variants'][1]['index']);
        $this->assertSame('doughnut', $result['variants'][0]['config']['chart']['type']);
        $this->assertSame('Доли — кольцевая', $result['variants'][0]['label']);
        $this->assertSame('Сравнение — столбцы', $result['variants'][1]['label']);

        // CRITICAL: no Widget persisted — variants are ephemeral proposals.
        $this->assertSame(0, Widget::count(), 'propose must not create any Widget');
        $chat->refresh();
        $this->assertNull($chat->widget_id, 'propose must not pin a widget to the chat');
    }

    public function test_propose_widget_variants_drops_invalid_keeps_valid(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $variants = json_encode([
            ['label' => 'OK', 'config' => $this->variantConfig('bar', 'count', 'cnt')],
            // Invalid: no group_by, no chart.
            ['label' => 'Broken', 'config' => ['primary_model' => 'EstateDeals', 'aggregates' => [['fn' => 'count', 'as' => 'cnt']]]],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'propose_widget_variants', [$variants]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, $resultJson);
        $this->assertSame(1, $result['variants_count'], 'only the valid variant survives');
        $this->assertArrayHasKey('rejected', $result);
        $this->assertSame(1, $result['variants'][0]['index']);
        $this->assertSame(0, Widget::count());
    }

    public function test_propose_widget_variants_all_invalid_fails_without_persisting(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $variants = json_encode([
            ['label' => 'Broken 1', 'config' => ['primary_model' => 'EstateDeals']],
            ['label' => 'Broken 2', 'config' => ['primary_model' => 'NoSuchModel', 'group_by' => ['fields' => ['x']], 'aggregates' => [['fn' => 'count', 'as' => 'c']], 'chart' => ['type' => 'bar', 'label_field' => 'x', 'value_field' => 'c']]],
        ]);

        $resultJson = $this->invokeTool($tool, $chat, 'propose_widget_variants', [$variants]);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true, 'all-invalid must fail: ' . $resultJson);
        $this->assertArrayHasKey('rejected', $result);
        $this->assertSame(0, Widget::count());
    }

    public function test_propose_widget_variants_rejects_non_array_payload(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $resultJson = $this->invokeTool($tool, $chat, 'propose_widget_variants', ['{"not":"an array"}']);
        $result = json_decode($resultJson, true);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('array', $result['error']);
    }

    public function test_propose_widget_variants_caps_at_four(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        $five = [];
        foreach (['bar', 'pie', 'doughnut', 'line', 'bar'] as $i => $type) {
            $five[] = ['label' => "V{$i}", 'config' => $this->variantConfig($type, 'count', 'cnt')];
        }

        $resultJson = $this->invokeTool($tool, $chat, 'propose_widget_variants', [json_encode($five)]);
        $result = json_decode($resultJson, true);

        $this->assertTrue($result['success'] ?? false, $resultJson);
        $this->assertSame(4, $result['variants_count'], 'variants are capped at 4');
    }

    // -------------------------------------------------------------------------
    // event emission
    // -------------------------------------------------------------------------

    public function test_propose_widget_variants_emits_widget_variants_event(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // A real assistant message + emitter so the event row is written.
        $assistant = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $chat->user_id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => '',
            'status'     => ChatMessage::STATUS_RUNNING,
        ]);
        $emitter = new ChatEventEmitter($assistant->id);

        $variants = json_encode([
            ['label' => 'A', 'config' => $this->variantConfig('bar', 'count', 'cnt')],
            ['label' => 'B', 'config' => $this->variantConfig('pie', 'count', 'cnt')],
        ]);

        $this->invokeTool($tool, $chat, 'propose_widget_variants', [$variants], null, $emitter);

        $event = ChatMessageEvent::where('chat_message_id', $assistant->id)
            ->where('type', ChatMessageEvent::TYPE_WIDGET_VARIANTS)
            ->first();

        $this->assertNotNull($event, 'a widget_variants event must be emitted');
        $this->assertCount(2, $event->payload['variants']);
        $this->assertSame(1, $event->payload['variants'][0]['index']);
    }

    // -------------------------------------------------------------------------
    // selection loop: chosen variant's config → create_widget
    // -------------------------------------------------------------------------

    public function test_chosen_variant_config_creates_widget_via_create_widget(): void
    {
        $chat = $this->makeChat();
        $tool = $this->app->make(WidgetTool::class);

        // Step 1: propose.
        $variants = json_encode([
            ['label' => 'Доли', 'config' => $this->variantConfig('doughnut', 'count', 'cnt')],
            ['label' => 'Выручка', 'config' => $this->variantConfig('bar', 'sum', 'total')],
        ]);
        $proposeResult = json_decode($this->invokeTool($tool, $chat, 'propose_widget_variants', [$variants]), true);

        // Step 2: user picks variant 2 — feed its exact config into create_widget.
        $chosen = $proposeResult['variants'][1]; // index 2
        $name = json_encode(['ru' => $chosen['label'], 'en' => $chosen['label']]);

        $createResult = json_decode(
            $this->invokeTool($tool, $chat->fresh(), 'create_widget', [$name, json_encode($chosen['config'])]),
            true,
        );

        $this->assertTrue($createResult['success'] ?? false, 'chosen variant must create a widget: ' . json_encode($createResult));
        $this->assertTrue($createResult['created'] ?? false);

        $widget = Widget::find($createResult['widget_id']);
        $this->assertNotNull($widget);
        $this->assertSame('bar', $widget->config['chart']['type']);
        $this->assertSame('sum', $widget->config['aggregates'][0]['fn']);
        $this->assertSame(1, Widget::count(), 'exactly one widget created from the chosen variant');

        // chat pinned to the created widget (decision N4).
        $chat->refresh();
        $this->assertSame($widget->id, $chat->widget_id);
    }
}
