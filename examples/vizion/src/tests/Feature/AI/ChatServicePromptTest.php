<?php

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\ChatService;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Asserts the structure of the system prompt produced by ChatService for both
 * chat types (quick_qa, report_generation). The system prompt is the single
 * biggest lever on AI behaviour — we don't trust the LLM to know what year it
 * is or where its scope ends; we tell it explicitly. These tests pin the
 * presence of those guardrails so a careless edit to ChatService can't quietly
 * drop them.
 *
 * No real Prism calls happen here: we invoke the protected prompt builders
 * directly via reflection. This keeps the suite fast and deterministic.
 */
class ChatServicePromptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MacroData ConnectionService gets resolved transitively (DataProbeService
     * is constructor-injected into ChatService → ReportTool). Stub it so
     * nothing tries to open MySQL during the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $stub = new class extends ConnectionService {
            public function __construct() {}

            public function connect(Company $company): void {}

            public function test(Company $company): bool
            {
                return true;
            }
        };

        $this->app->instance(ConnectionService::class, $stub);

        // ConfigNormalizer hits reflection on real MacroData models in its real ctor —
        // stub with an empty canonical map; these tests don't normalize anything.
        $normalizerStub = new class extends ConfigNormalizer {
            public function __construct() {}

            public function getCanonicalMap(): array
            {
                return ['models' => [], 'relations' => [], 'related' => []];
            }
        };

        $this->app->instance(ConfigNormalizer::class, $normalizerStub);
    }

    private function makeChat(string $type, string $locale = 'ru'): Chat
    {
        $company = Company::create(['name' => 'Prompt Test Co']);
        $user = User::forceCreate([
            'name' => 'Tester',
            'email' => 'prompt+'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'role' => 'analyst',
            'locale' => $locale,
        ]);

        return Chat::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => $type,
        ]);
    }

    /**
     * Pull the protected buildSystemPrompt() output. Reflection avoids exposing
     * it on the public API just for testing.
     *
     * Passing $reportContext exercises the in-report quick_qa branch added
     * alongside this test suite: when the frontend supplies a {primaryModel,
     * columns, filters} snapshot, the prompt swaps the slim 10 KB catalog
     * for a per-model semantic note + report header. Null = legacy path.
     */
    private function buildPrompt(Chat $chat, ?array $reportContext = null): string
    {
        $svc = $this->app->make(ChatService::class);
        $m = new ReflectionMethod($svc, 'buildSystemPrompt');
        $m->setAccessible(true);

        return $m->invoke($svc, $chat, $reportContext);
    }

    // -------------------------------------------------------------------------
    // Date injection — both modes get a wall-clock anchor
    // -------------------------------------------------------------------------

    public function test_quick_qa_prompt_includes_current_date_iso_and_russian_month(): void
    {
        // Freeze time so the assertion is deterministic
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0));

        try {
            $chat = $this->makeChat('quick_qa');
            $prompt = $this->buildPrompt($chat);

            $this->assertStringContainsString('## Текущая дата', $prompt);
            $this->assertStringContainsString('2026-05-15', $prompt);
            // Russian month formatting via Carbon ISO localized — should be "май 2026"
            // (case-insensitive match because the locale plugin may return "Май")
            $this->assertMatchesRegularExpression('/май\s+2026/iu', $prompt);
            $this->assertStringContainsString('относительных периодах', $prompt);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_report_generation_prompt_includes_current_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0));

        try {
            $chat = $this->makeChat('report_generation');
            $prompt = $this->buildPrompt($chat);

            $this->assertStringContainsString('## Текущая дата', $prompt);
            $this->assertStringContainsString('2026-05-15', $prompt);
        } finally {
            Carbon::setTestNow();
        }
    }

    // -------------------------------------------------------------------------
    // Scope guard — quick_qa only
    // -------------------------------------------------------------------------

    public function test_quick_qa_prompt_includes_off_topic_refusal_scope(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('## Зона ответственности', $prompt);
        $this->assertStringContainsString('AI-аналитик данных недвижимости', $prompt);
        $this->assertStringContainsString('анекдоты', $prompt);
        $this->assertStringContainsString('погода', $prompt);
        $this->assertStringContainsString('Никогда не выполняй off-topic', $prompt);
    }

    public function test_report_generation_prompt_does_not_include_scope_block(): void
    {
        // The scope block lives only in quick_qa. report_generation has its own
        // task (build reports) and a different failure mode.
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringNotContainsString('## Зона ответственности', $prompt);
    }

    // -------------------------------------------------------------------------
    // Redirect action marker — quick_qa only
    // -------------------------------------------------------------------------

    public function test_quick_qa_prompt_describes_redirect_action_marker(): void
    {
        $chat = $this->makeChat('quick_qa', 'ru');
        $prompt = $this->buildPrompt($chat);

        // The contract for the front-end: exact action name and required fields.
        $this->assertStringContainsString('redirect_to_report_generation', $prompt);
        $this->assertStringContainsString('"action": "redirect_to_report_generation"', $prompt);
        $this->assertStringContainsString('"prompt"', $prompt);
        $this->assertStringContainsString('"label"', $prompt);
        $this->assertStringContainsString('AI-конструктор', $prompt);
        // The "two-step" flow: ask, then on yes emit marker.
        $this->assertStringContainsString('согласился', $prompt);
    }

    public function test_quick_qa_redirect_block_uses_english_label_for_en_locale(): void
    {
        $chat = $this->makeChat('quick_qa', 'en');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('Open in AI Report Constructor', $prompt);
        $this->assertStringNotContainsString('Открыть в AI-конструкторе отчётов', $prompt);
    }

    public function test_report_generation_prompt_does_not_describe_redirect_marker(): void
    {
        // report_generation receives the prefill, doesn't emit the marker itself.
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringNotContainsString('redirect_to_report_generation', $prompt);
    }

    // -------------------------------------------------------------------------
    // Sanity — old behavior preserved
    // -------------------------------------------------------------------------

    public function test_quick_qa_prompt_still_includes_pii_guard_and_query_data_doc(): void
    {
        // Regression: adding new blocks must not displace existing safety rails.
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('## Безопасность данных', $prompt);
        $this->assertStringContainsString('query_data', $prompt);
        $this->assertStringContainsString('probe_data', $prompt);
    }

    public function test_report_generation_prompt_still_includes_tool_instructions(): void
    {
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('create_report', $prompt);
        $this->assertStringContainsString('update_report', $prompt);
        $this->assertStringContainsString('probe_data', $prompt);
    }

    // -------------------------------------------------------------------------
    // Report-generation aggregation / overflow guards (29% accuracy audit fix)
    // -------------------------------------------------------------------------

    /**
     * Aggregation breakdowns over manager / status / channel dimensions have no
     * HasMany on the primary models, so the AI must redirect them to the widget
     * generator instead of emitting a flat list and lying that "grouping isn't
     * supported". The report_generation prompt must carry the widget-redirect
     * block (marker + label) so the frontend's action-marker parser can render
     * the CTA.
     */
    public function test_report_generation_prompt_includes_widget_redirect_block(): void
    {
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('redirect_to_widget_generation', $prompt);
        $this->assertStringContainsString('Открыть в генераторе виджетов', $prompt);
    }

    public function test_report_generation_widget_redirect_block_localised_en(): void
    {
        $chat = $this->makeChat('report_generation', 'en');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('redirect_to_widget_generation', $prompt);
        $this->assertStringContainsString('Open in Widget Generator', $prompt);
    }

    /**
     * Context-overflow mitigation: the report_generation prompt must instruct
     * the AI to probe minimally (heavy probing blew GLM-5.1's window — error
     * 1261). Pin the directive so a future prompt edit can't silently drop it.
     */
    public function test_report_generation_prompt_instructs_minimal_probing(): void
    {
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        $this->assertStringContainsString('Минимизируй probe_data', $prompt);
    }

    // -------------------------------------------------------------------------
    // Quarter semantics — "last quarter" must be the calendar quarter, not 90d
    // -------------------------------------------------------------------------

    /**
     * QA audit Q6: the AI read "последний квартал" as "today minus 90 days".
     * The shared date block now spells out calendar quarter boundaries and an
     * explicit "previous completed calendar quarter" definition. Freeze time in
     * mid-May 2026 (Q2) so the previous quarter is Q1 = 01.01–31.03.
     */
    public function test_date_block_defines_calendar_quarter_boundaries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0));

        try {
            $chat = $this->makeChat('report_generation');
            $prompt = $this->buildPrompt($chat);

            // Q labels present and previous-quarter window resolved to Q1 2026.
            $this->assertStringContainsString('Календарные кварталы', $prompt);
            $this->assertStringContainsString('2026-01-01', $prompt);
            $this->assertStringContainsString('2026-03-31', $prompt);
            // Explicitly distinguishes from the 90-day misreading.
            $this->assertStringContainsString('предыдущий завершённый календарный квартал', $prompt);
        } finally {
            Carbon::setTestNow();
        }
    }

    // -------------------------------------------------------------------------
    // Context budget — quick_qa uses the slim catalog, not the full guide
    // -------------------------------------------------------------------------

    /**
     * Regression: GLM-5.1 used to reject quick_qa with "Prompt exceeds max
     * length" (error code 1261) because the system prompt loaded the full
     * ~260 KB REPORTS_GUIDE.md. quick_qa now loads QUICK_QA_PROMPT.md (~10 KB)
     * which gives the AI just enough to pick a model for probe_data / query_data
     * without the full report-config schema. This test pins both halves of the
     * contract: slim catalog is present, full guide is not.
     */
    public function test_quick_qa_uses_slim_system_prompt(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        // Slim catalog marker: a heading unique to QUICK_QA_PROMPT.md.
        $this->assertStringContainsString('Каталог моделей MacroData (для quick_qa)', $prompt);

        // Slim catalog still names the most-used models so the LLM can route
        // probe_data / query_data calls without being lost.
        $this->assertStringContainsString('EstateDeals', $prompt);
        $this->assertStringContainsString('EstateSells', $prompt);
        $this->assertStringContainsString('Finances', $prompt);

        // Full guide markers — strings that appear in REPORTS_GUIDE.md but NOT
        // in the slim catalog. If any of these slip back in, someone has
        // accidentally re-introduced the heavy guide into quick_qa.
        //
        // The REPORTS_GUIDE.md leads with "### 0.1 Жёсткие правила (ALWAYS / NEVER)"
        // and the literal "Decision tree «что показать в ячейке»" — neither
        // are in QUICK_QA_PROMPT.md.
        $this->assertStringNotContainsString('Жёсткие правила (ALWAYS / NEVER)', $prompt);
        $this->assertStringNotContainsString('Decision tree «что показать в ячейке»', $prompt);

        // Hard size budget: quick_qa prompt MUST stay well under 50 KB. A
        // freshly-loaded slim prompt is ~10 KB catalog + ~6 KB scope/instructions
        // ≈ 16 KB total. The 50 KB ceiling gives plenty of headroom for future
        // additions without inviting the full guide back.
        $this->assertLessThan(
            50_000,
            strlen($prompt),
            'quick_qa prompt has grown past 50 KB — did someone re-add REPORTS_GUIDE.md? '
            . 'Current size: ' . strlen($prompt) . ' bytes.'
        );
    }

    /**
     * Symmetric assertion: report_generation continues to load the full guide.
     * If this ever flips (slim catalog leaks here), the AI will lose access to
     * column-type schemas / expression docs / dashboard widget format and start
     * producing invalid report configs.
     */
    public function test_report_generation_uses_full_reports_guide(): void
    {
        $chat = $this->makeChat('report_generation');
        $prompt = $this->buildPrompt($chat);

        // Markers from REPORTS_GUIDE.md that wouldn't be in QUICK_QA_PROMPT.md.
        $this->assertStringContainsString('Жёсткие правила (ALWAYS / NEVER)', $prompt);

        // Full guide is large — assert size floor so a future "load wrong file"
        // bug surfaces here. REPORTS_GUIDE.md is ~260 KB at time of writing;
        // 100 KB floor is conservative.
        $this->assertGreaterThan(
            100_000,
            strlen($prompt),
            'report_generation prompt is unexpectedly small — was the full guide replaced '
            . 'with the slim catalog by mistake? Current size: ' . strlen($prompt) . ' bytes.'
        );
    }

    // -------------------------------------------------------------------------
    // In-report quick_qa — slim per-model prompt instead of full catalog
    // -------------------------------------------------------------------------

    /**
     * The primary feature: when the frontend passes a report_context payload
     * with a known primaryModel, the system prompt swaps the catalog for the
     * curated semantic note. We pin:
     *   - the report header is present (title + columns + filters)
     *   - the per-model note shows up
     *   - the slim catalog header from QUICK_QA_PROMPT.md is NOT injected
     *   - prompt is strictly smaller than the general quick_qa prompt
     */
    public function test_quick_qa_with_report_context_uses_slim_in_report_prompt(): void
    {
        $chat = $this->makeChat('quick_qa');

        $reportContext = [
            'primaryModel' => 'Finances',
            'reportId'     => 123,
            'reportTitle'  => 'Дебиторка по сделкам',
            'columns'      => ['deal_id', 'sum', 'pay_date', 'types_id', 'status'],
            'filters'      => [
                'pay_date' => ['from' => '2026-01-01', 'to' => '2026-05-31'],
                'status'   => 1,
            ],
        ];

        $prompt = $this->buildPrompt($chat, $reportContext);

        // Header: title + primary model + columns line.
        $this->assertStringContainsString('## Текущий отчёт', $prompt);
        $this->assertStringContainsString('Дебиторка по сделкам', $prompt);
        $this->assertStringContainsString('Основная модель:** Finances', $prompt);
        $this->assertStringContainsString('deal_id', $prompt);
        $this->assertStringContainsString('pay_date', $prompt);

        // Applied-filter snapshot is serialised verbatim so the LLM sees the
        // same date range the user is looking at.
        $this->assertStringContainsString('2026-01-01', $prompt);

        // The curated semantic note for Finances must show up — this is the
        // whole point of dropping the catalog: replace one big general
        // reference with one targeted reference.
        $this->assertStringContainsString('types_id = 3786', $prompt);
        $this->assertStringContainsString('Дебиторка', $prompt);

        // The slim catalog heading is unique to QUICK_QA_PROMPT.md and must
        // NOT appear when in-report mode is active.
        $this->assertStringNotContainsString(
            'Каталог моделей MacroData (для quick_qa)',
            $prompt,
            'in-report prompt must NOT load the 10 KB general catalog'
        );

        // Sanity: scope + redirect guard still present (in-report mode keeps
        // the off-topic refusal behaviour — only the catalog is dropped).
        $this->assertStringContainsString('## Зона ответственности', $prompt);
        $this->assertStringContainsString('redirect_to_report_generation', $prompt);

        // No affirmative create_report / update_report tool docs — quick_qa
        // mode doesn't expose these tools. The literal substring "create_report"
        // *does* legitimately appear in the in-report prompt as a negative
        // instruction ("НЕ вызывай create_report / update_report") so we can't
        // do a raw substring NotContains. Instead, pin the absence of the
        // affirmative-use instruction the report_generation prompt carries and
        // the absence of the full REPORTS_GUIDE.md markers that document tool
        // schemas in detail.
        $this->assertStringNotContainsString(
            'используй инструменты probe_data, create_report',
            $prompt,
            'in-report quick_qa must NOT carry the report_generation "use these tools" instruction'
        );
        $this->assertStringNotContainsString(
            'используй update_report для изменений',
            $prompt,
            'in-report quick_qa must NOT instruct the model to call update_report'
        );
        $this->assertStringNotContainsString(
            'используй create_report для создания',
            $prompt,
            'in-report quick_qa must NOT instruct the model to call create_report'
        );
        // REPORTS_GUIDE.md is the only place where the create_report tool schema
        // is documented in depth — its presence would mean quick_qa accidentally
        // loaded the heavy guide.
        $this->assertStringNotContainsString('Жёсткие правила (ALWAYS / NEVER)', $prompt);

        // Hard size budget: in-report mode should be SMALLER than general
        // quick_qa (which loads the 10 KB catalog). With a curated note of
        // ~500 chars + report header, total stays well under 30 KB.
        $this->assertLessThan(
            30_000,
            strlen($prompt),
            'in-report quick_qa prompt has grown past 30 KB — semantic notes '
            . 'should be slim. Current size: ' . strlen($prompt) . ' bytes.'
        );
    }

    /**
     * When no report_context is supplied, behaviour must be unchanged: the
     * general quick_qa path with the slim catalog. This is the back-compat
     * fallback for any old frontend that doesn't yet send the new field.
     */
    public function test_quick_qa_without_report_context_falls_back_to_quick_qa_catalog(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat, null);

        // Slim catalog header from QUICK_QA_PROMPT.md must be present.
        $this->assertStringContainsString(
            'Каталог моделей MacroData (для quick_qa)',
            $prompt
        );

        // In-report header strings must NOT appear (no report on screen).
        $this->assertStringNotContainsString('## Текущий отчёт', $prompt);
        $this->assertStringNotContainsString('## Справка по модели', $prompt);
    }

    /**
     * Defensive shape check: payload without `primaryModel` (or with a
     * non-string primaryModel) must not flip into in-report mode. Bad input
     * silently falls through to the legacy general catalog rather than
     * producing a broken prompt with an empty model name.
     */
    public function test_quick_qa_ignores_report_context_without_primary_model(): void
    {
        $chat = $this->makeChat('quick_qa');

        // Missing primaryModel — frontend bug or partial payload.
        $bad1 = ['columns' => ['x', 'y'], 'filters' => ['z' => 1]];
        $prompt1 = $this->buildPrompt($chat, $bad1);
        $this->assertStringContainsString('Каталог моделей MacroData (для quick_qa)', $prompt1);
        $this->assertStringNotContainsString('## Текущий отчёт', $prompt1);

        // primaryModel present but not a string.
        $bad2 = ['primaryModel' => ['EstateDeals'], 'columns' => []];
        $prompt2 = $this->buildPrompt($chat, $bad2);
        $this->assertStringContainsString('Каталог моделей MacroData (для quick_qa)', $prompt2);

        // primaryModel is empty string — falsy via `empty()` check.
        $bad3 = ['primaryModel' => '', 'columns' => []];
        $prompt3 = $this->buildPrompt($chat, $bad3);
        $this->assertStringContainsString('Каталог моделей MacroData (для quick_qa)', $prompt3);
    }

    /**
     * Unknown / exotic primary models still produce a usable prompt — the
     * fallback semantic note tells the LLM to use probe_data. This stops
     * a new MacroData model added without a ModelSemanticNotes entry from
     * breaking the in-report path.
     */
    public function test_quick_qa_with_unknown_primary_model_uses_generic_note(): void
    {
        $chat = $this->makeChat('quick_qa');

        $reportContext = [
            'primaryModel' => 'SomeBrandNewModel',
            'columns'      => ['a', 'b'],
            'filters'      => [],
        ];

        $prompt = $this->buildPrompt($chat, $reportContext);

        // In-report header still present.
        $this->assertStringContainsString('## Текущий отчёт', $prompt);
        $this->assertStringContainsString('Основная модель:** SomeBrandNewModel', $prompt);

        // Generic fallback semantic note from ModelSemanticNotes::getNote().
        $this->assertStringContainsString('подробной семантической справки нет', $prompt);
        $this->assertStringContainsString('probe_data', $prompt);

        // Heavy catalog still NOT loaded.
        $this->assertStringNotContainsString(
            'Каталог моделей MacroData (для quick_qa)',
            $prompt
        );
    }

    // -------------------------------------------------------------------------
    // Field-name cheat-sheet + "don't repeat the same column-not-found" hard rule
    // -------------------------------------------------------------------------

    /**
     * Regression: AI used to guess "standard" Laravel field names (Users.name,
     * EstateDeals.user_id) on quick_qa and burn 6 tool calls in a row on
     * "Unknown column" errors before finally calling probe_data. The fix is
     * twofold:
     *   1. Surface the most-used nonstandard fields in QUICK_QA_PROMPT.md
     *      cheat-sheet so the model sees the real names up front.
     *   2. Add a hard "don't repeat the same Column-not-found error" rule both
     *      in QUICK_QA_PROMPT.md AND inline in the prompt itself.
     *
     * This test pins both halves so a future edit can't quietly drop them.
     */
    public function test_quick_qa_prompt_includes_nonstandard_field_cheatsheet_and_repeat_guard(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        // Cheat-sheet markers — confirmed nonstandard fields the AI was caught
        // guessing wrong on. Each one is a real failure mode from chat logs.
        $this->assertStringContainsString('manager_id', $prompt,
            'cheat-sheet must surface EstateDeals.manager_id (AI used to guess user_id)');
        $this->assertStringContainsString('users_name', $prompt,
            'cheat-sheet must surface Users.users_name (AI used to guess name)');
        $this->assertStringContainsString('deal_id', $prompt,
            'cheat-sheet must surface EstateDeals.deal_id PK (AI used to guess id)');
        $this->assertStringContainsString('summa', $prompt,
            'cheat-sheet must surface Finances.summa (AI used to guess sum/amount)');
        $this->assertStringContainsString('estate_sell_id', $prompt,
            'cheat-sheet must surface EstateSells.estate_sell_id PK');

        // Hard repeat-guard — both flavours of the rule (slim summary in the
        // prompt itself + detailed rule inside the catalog). Match a stable
        // anchor phrase that won't be paraphrased away on minor rewordings.
        $this->assertMatchesRegularExpression(
            '/Unknown column.*probe_data/su',
            $prompt,
            'prompt must instruct the model to switch to probe_data on Unknown column errors'
        );
    }

    /**
     * Same guard for the in-report path. The user lands here when MiniChat is
     * opened on a report page — they're MORE likely to ask "show me by managers"
     * than in the general catalog flow, so the manager_id / users_name hint is
     * actually even more load-bearing here.
     */
    public function test_in_report_quick_qa_prompt_includes_field_name_guard(): void
    {
        $chat = $this->makeChat('quick_qa');

        $reportContext = [
            'primaryModel' => 'EstateDeals',
            'reportId'     => 7,
            'reportTitle'  => 'Сделки за квартал',
            'columns'      => ['deal_id', 'deal_date', 'deal_sum'],
            'filters'      => [],
        ];

        $prompt = $this->buildPrompt($chat, $reportContext);

        // The slim "watch out for nonstandard names" inline block is here too.
        $this->assertStringContainsString('manager_id', $prompt);
        $this->assertStringContainsString('users_name', $prompt);

        // Repeat-guard wording is here too.
        $this->assertMatchesRegularExpression(
            '/Unknown column.*probe_data/su',
            $prompt,
            'in-report prompt must carry the same Unknown-column → probe_data switch rule'
        );
    }

    /**
     * report_generation mode must ignore report_context entirely — the field
     * is a quick_qa-only signal. The full REPORTS_GUIDE.md is the right
     * reference for assembling report configs; an in-report short note would
     * actively harm tool-calling.
     */
    public function test_report_generation_still_uses_full_reports_guide_even_with_report_context(): void
    {
        $chat = $this->makeChat('report_generation');

        $reportContext = [
            'primaryModel' => 'EstateDeals',
            'columns'      => ['deal_sum', 'deal_date'],
            'filters'      => [],
        ];

        $prompt = $this->buildPrompt($chat, $reportContext);

        // Still the full guide path, regardless of report_context.
        $this->assertStringContainsString('Жёсткие правила (ALWAYS / NEVER)', $prompt);
        $this->assertGreaterThan(100_000, strlen($prompt));

        // In-report header must NOT appear in report_generation prompts.
        $this->assertStringNotContainsString('## Текущий отчёт', $prompt);
        $this->assertStringNotContainsString('## Справка по модели', $prompt);
    }

    // -------------------------------------------------------------------------
    // Status dictionary — MACRO platform-constant IDs, with "filter by ID, not name" rule
    // -------------------------------------------------------------------------

    /**
     * Regression: AI used to filter EstateBuys / EstateDeals / EstateSells by
     * `status_name` substring. That works on RU clients but silently returns 0
     * rows on Buildera (EN client). Since the IDs are platform constants and
     * only the language plays around, we surface the canonical ID dictionary
     * inside the slim quick_qa catalog so the model can use stable numeric
     * filters from the first tool call.
     *
     * Pinned strings: the ⭐-marked terminal-state names (RU + EN), `status = 100`
     * worked example, and finances status enum (1 / 3 / 50).
     */
    public function test_quick_qa_prompt_includes_status_dictionary(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        // Estate flow terminal state — both RU and EN names appear in the dictionary
        // (this is the whole point: model sees both translations so it falls back
        // on the constant ID instead of guessing language).
        $this->assertStringContainsString('Сделка проведена', $prompt);
        $this->assertStringContainsString('Done deal', $prompt);

        // Worked example demonstrating ID-based filter pattern.
        $this->assertStringContainsString('status = 100', $prompt);

        // Deal-specific terminal & canceled IDs.
        $this->assertStringContainsString('150', $prompt);
        $this->assertStringContainsString('140', $prompt);

        // Finances status legend present.
        $this->assertStringContainsString('finances.status', $prompt);
    }

    /**
     * The "filter by ID, not by name" rule has to be explicit — without it the
     * dictionary above just looks like reference material the model may or may
     * not act on. Pin both the rule headline and the headline substring so a
     * future rewording can't quietly delete it.
     */
    public function test_quick_qa_prompt_warns_about_filtering_by_id(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        // Rule headline — "Фильтруй по ID, а не по name" verbatim.
        $this->assertStringContainsString('Фильтруй по ID', $prompt);
        $this->assertMatchesRegularExpression('/Фильтруй по ID.*name/su', $prompt);

        // The reasoning anchor — language-drift between clients (Buildera = EN).
        $this->assertMatchesRegularExpression('/Buildera.*EN/su', $prompt);
    }

    /**
     * Custom statuses (`status_custom` / `custom_status_name`) are per-company —
     * each client invents their own ad-hoc labels ("Недозвон", "Касание без
     * ответа"). The AI must probe first, not invent an ID. Pin the rule + the
     * field names the AI should probe.
     */
    public function test_quick_qa_prompt_mentions_custom_status_probe(): void
    {
        $chat = $this->makeChat('quick_qa');
        $prompt = $this->buildPrompt($chat);

        // The two field names that hold per-company status data.
        $this->assertStringContainsString('status_custom', $prompt);
        $this->assertStringContainsString('custom_status_name', $prompt);

        // The instruction to probe first, not invent. Match a stable anchor:
        // "probe_data" near "status_custom" or "custom_status_name".
        $this->assertMatchesRegularExpression(
            '/(status_custom|custom_status_name)[^\n]{0,400}probe_data/su',
            $prompt,
            'prompt must instruct the model to probe for custom statuses before filtering'
        );
    }

    /**
     * In-report path gets a SHORTENED status block (key ⭐ IDs + filtering rules,
     * no full enum table). Same contract — pin "Фильтруй по ID", terminal state
     * IDs, deal-canceled exclusion, and the custom-status probe rule.
     */
    public function test_in_report_quick_qa_prompt_includes_status_block(): void
    {
        $chat = $this->makeChat('quick_qa');

        $reportContext = [
            'primaryModel' => 'EstateDeals',
            'reportId'     => 7,
            'reportTitle'  => 'Сделки за квартал',
            'columns'      => ['deal_id', 'deal_date', 'deal_sum'],
            'filters'      => [],
        ];

        $prompt = $this->buildPrompt($chat, $reportContext);

        // Filter-by-ID rule is here too.
        $this->assertStringContainsString('Фильтруй по ID', $prompt);

        // Key terminal IDs surfaced.
        $this->assertStringContainsString('150', $prompt);
        $this->assertStringContainsString('140', $prompt);
        $this->assertStringContainsString('100', $prompt);

        // Custom status probe rule replicated.
        $this->assertStringContainsString('status_custom', $prompt);
        $this->assertStringContainsString('custom_status_name', $prompt);

        // finances.types_id per-company warning replicated.
        $this->assertStringContainsString('types_id', $prompt);
    }
}
