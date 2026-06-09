<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\ChatService;
use App\Services\AI\ReportTool;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

/**
 * Integration test for ChatService's semantic-retry plumbing.
 *
 * The deep behavioural assertions (counter increments, hint escalation) live
 * in ReportToolDryRunTest, which exercises the tool closures directly. This
 * test focuses on the outer wiring: sendMessage() must construct a fresh
 * dryRunState per turn, pass it through ReportTool::getTools(), and surface
 * the final failure count in the persisted ChatMessage.metadata.
 *
 * Stubs:
 *   - Prism::fake — returns canned TextResponseFake's so no real LLM is invoked.
 *   - ConfigNormalizer — empty canonical map; tests don't exercise normalization.
 *   - ConnectionService / ReportDataService — no-op variants.
 */
class ChatServiceSemanticRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tame ConfigNormalizer reflection
        $this->app->instance(ConfigNormalizer::class, new class extends ConfigNormalizer {
            public function __construct() {}

            public function getCanonicalMap(): array
            {
                return ['models' => [], 'relations' => [], 'related' => []];
            }
        });

        // No-op MacroData connection
        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });

        // No-op data service — these tests don't exercise the dry-run branch
        // (covered in ReportToolDryRunTest); they verify state plumbing only.
        $this->app->instance(ReportDataService::class, new class extends ReportDataService {
            public function __construct() {}

            public function getData(
                \App\Models\Report $report,
                Company $company,
                User $user,
                array $params = []
            ): array {
                return ['id' => $report->id, 'data' => [], 'meta' => []];
            }
        });
    }

    private function makeChat(): Chat
    {
        $company = Company::create(['name' => 'SemRetryCo']);
        $user = User::forceCreate([
            'name' => 'Tester',
            'email' => 'sem+' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'role' => 'analyst',
            'locale' => 'ru',
        ]);

        return Chat::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => 'report_generation',
        ]);
    }

    public function test_send_message_passes_fresh_dry_run_state_to_report_tool_per_turn(): void
    {
        // Two fake responses — one per sendMessage() call. PrismFake serves
        // them in sequence; using one response across two calls throws
        // "Could not find a response for the request".
        Prism::fake([
            TextResponseFake::make()->withText('Готово, первый ход.'),
            TextResponseFake::make()->withText('Готово, второй ход.'),
        ]);

        // Sniff what ReportTool::getTools is called with so we can assert the
        // state object is fresh on each sendMessage().
        $observed = (object) ['states' => []];
        $reportTool = $this->app->make(ReportTool::class);
        $tracking = new class($reportTool, $observed) extends ReportTool {
            public function __construct(private readonly ReportTool $inner, private readonly object $sink)
            {
                // do NOT call parent ctor — we delegate everything to inner
            }

            public function getTools(Chat $chat, ?object $dryRunState = null, ?\App\Services\AI\ChatEventEmitter $emitter = null): array
            {
                // Record the per-call state object identity so the test can
                // verify two consecutive sendMessage()s get two different ones.
                $this->sink->states[] = $dryRunState;

                return $this->inner->getTools($chat, $dryRunState);
            }
        };

        $this->app->instance(ReportTool::class, $tracking);

        $chat = $this->makeChat();
        $service = $this->app->make(ChatService::class);

        $service->sendMessage($chat, 'first turn');
        $service->sendMessage($chat->fresh(), 'second turn');

        $this->assertCount(2, $observed->states);
        $this->assertNotNull($observed->states[0]);
        $this->assertNotNull($observed->states[1]);
        $this->assertNotSame(
            $observed->states[0],
            $observed->states[1],
            'sendMessage must build a FRESH dryRunState per turn — a long-lived counter would prematurely trip the stop directive on later turns.'
        );
        $this->assertSame(0, $observed->states[0]->failures);
        $this->assertSame(0, $observed->states[1]->failures);
    }

    public function test_send_message_surfaces_dry_run_failures_in_message_metadata_when_non_zero(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Не получилось построить отчёт, уточните запрос.'),
        ]);

        // Inject a ReportTool that pretends two dry-runs failed in the turn.
        // We don't actually invoke Prism's tool-call loop here — too brittle to
        // simulate inside a fake. Instead we bump the shared state during
        // getTools() so ChatService observes it post-call.
        $reportTool = $this->app->make(ReportTool::class);
        $simulating = new class($reportTool) extends ReportTool {
            public function __construct(private readonly ReportTool $inner)
            {
                // do NOT call parent ctor
            }

            public function getTools(Chat $chat, ?object $dryRunState = null, ?\App\Services\AI\ChatEventEmitter $emitter = null): array
            {
                if ($dryRunState !== null) {
                    $dryRunState->failures = 2; // simulate two tool-side failures
                }
                return $this->inner->getTools($chat, $dryRunState);
            }
        };

        $this->app->instance(ReportTool::class, $simulating);

        $chat = $this->makeChat();
        $service = $this->app->make(ChatService::class);

        $message = $service->sendMessage($chat, 'build a report please');

        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertSame(2, $message->metadata['dry_run_failures'] ?? null);
    }

    public function test_send_message_does_not_surface_failure_count_when_zero(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('OK'),
        ]);

        $chat = $this->makeChat();
        $service = $this->app->make(ChatService::class);

        $message = $service->sendMessage($chat, 'hello');

        $this->assertNotNull($message);
        $this->assertArrayNotHasKey(
            'dry_run_failures',
            $message->metadata ?? [],
            'Zero failures should NOT add the dry_run_failures key — keeps metadata clean for happy turns.'
        );
    }
}
