<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Loaders\MigrationLoader;
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Migration\Models\ExternalRef;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Load tests — drive MigrationLoader against tiny on-disk JSONL staging fixtures
 * (NOT live AMO) in SQLite :memory:. Covers idempotency, FK order, historical
 * backdating (raw insert) and the unique-client stamp.
 */
class AmoLoadTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $relative = 'amo-load-test-'.uniqid();
        config(['amo_migration.api.staging_path' => $relative]);
        $this->stagingDir = storage_path($relative);
        @mkdir($this->stagingDir, 0775, true);

        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            foreach (glob($this->stagingDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stagingDir);
        }

        parent::tearDown();
    }

    private function seedReferenceData(): void
    {
        // The import fallback user must exist (resolver throws otherwise).
        User::factory()->create(['email' => 'import-amo@mgcrm.local', 'full_name' => 'Импорт АМО']);
        // A mapped manager (user_map[2435437]) so owner resolution finds a real user.
        User::factory()->create(['email' => 'b.yadykin@macroprop.tech', 'full_name' => 'B Y']);

        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);
        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification', 'sort_order' => 4]);
        PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'code' => 'success', 'sort_order' => 13]);
        PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id, 'code' => 'lost', 'sort_order' => 14]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $files  entity => rows
     */
    private function writeStaging(array $files): void
    {
        foreach (['leads', 'contacts', 'companies', 'tasks', 'events', 'notes'] as $entity) {
            $rows = $files[$entity] ?? [];
            $lines = array_map(
                static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE),
                $rows,
            );
            file_put_contents($this->stagingDir.'/'.$entity.'.jsonl', implode("\n", $lines)."\n");
        }
    }

    private function loader(): MigrationLoader
    {
        return MigrationLoader::make(new StagingReader($this->stagingDir));
    }

    /**
     * One open deal with an embedded company + main contact, plus a genesis event,
     * a stage change, an open task and a note.
     */
    private function fullFixture(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 1000,
                'name' => 'Deal Alpha',
                'price' => 2500,
                'status_id' => 53233417, // qualification
                'pipeline_id' => 6149857,
                'responsible_user_id' => 2435437, // mapped manager
                'created_by' => 2435437,
                'created_at' => 1577836800, // 2020-01-01
                '_embedded' => [
                    'companies' => [['id' => 5000, 'is_main' => true]],
                    'contacts' => [['id' => 7000, 'is_main' => true]],
                ],
                'custom_fields_values' => [
                    ['field_id' => 711078, 'values' => [['value' => 'г. Москва', 'enum_id' => 1188488]]],
                ],
            ]],
            'companies' => [[
                'id' => 5000, 'name' => 'ООО Альфа', 'created_by' => 2435437,
                'custom_fields_values' => [
                    ['field_id' => 711074, 'values' => [['value' => 'Общество Альфа']]],
                ],
            ]],
            'contacts' => [[
                'id' => 7000, 'name' => 'Иван Контактов', 'created_by' => 2435437,
                'custom_fields_values' => [
                    ['field_code' => 'PHONE', 'values' => [['value' => '+79990000000', 'enum_code' => 'WORK']]],
                ],
            ]],
            'events' => [
                ['id' => 'e1', 'type' => 'lead_added', '_lead_id' => 1000, 'created_at' => 1577836800, 'created_by' => 2435437],
                [
                    'id' => 'e2', 'type' => 'lead_status_changed', '_lead_id' => 1000,
                    'created_at' => 1577923200, 'created_by' => 2435437,
                    'value_before' => [['lead_status' => ['id' => 53169821, 'pipeline_id' => 6149857]]],
                    'value_after' => [['lead_status' => ['id' => 53233417, 'pipeline_id' => 6149857]]],
                ],
            ],
            'tasks' => [[
                'id' => 9001, 'entity_type' => 'leads', 'entity_id' => 1000,
                'task_type_id' => 1, 'text' => 'Перезвонить', 'complete_till' => 1577923200,
                'is_completed' => false, 'created_at' => 1577836800, 'responsible_user_id' => 2435437,
            ]],
            'notes' => [[
                'id' => 'n1', '_lead_id' => 1000, 'note_type' => 'common',
                'params' => ['text' => 'Первый контакт'], 'created_at' => 1577836900, 'created_by' => 2435437,
            ]],
        ]);
    }

    public function test_loads_full_deal_graph_in_fk_order(): void
    {
        $this->fullFixture();

        $this->loader()->load();

        // Company + requisite.
        $company = Company::query()->where('name', 'ООО Альфа')->first();
        $this->assertNotNull($company);
        $this->assertSame('ru', $company->country_code);
        $this->assertSame(1, $company->requisites()->count());

        // Contact + channel + link.
        $contact = Contact::query()->where('full_name', 'Иван Контактов')->first();
        $this->assertNotNull($contact);
        $this->assertSame('+79990000000', $contact->phone);
        $this->assertSame(1, $contact->companies()->count());

        // Deal.
        $deal = Deal::query()->where('title', 'Deal Alpha')->first();
        $this->assertNotNull($deal);
        $this->assertSame(250000, $deal->amount); // 2500 × 100
        $this->assertTrue($deal->amount_locked);
        $this->assertSame($company->id, $deal->company_id);
        $this->assertNotNull($deal->company_requisite_id);

        // deal_contacts with primary.
        $this->assertSame(1, DealContact::query()->where('deal_id', $deal->id)->where('is_primary', true)->count());

        // Timeline: genesis + 1 stage change = 2 stage-history rows.
        $this->assertSame(2, DealStageHistory::query()->where('deal_id', $deal->id)->count());

        // Activities: 1 task + 1 note.
        $this->assertSame(2, DB::table('activities')->where('target_id', $deal->id)->count());

        // EntityLog: created + stage_changed.
        $this->assertSame(2, DB::table('entity_logs')->where('subject_id', $deal->id)->count());
    }

    public function test_idempotent_second_run_creates_no_duplicates(): void
    {
        $this->fullFixture();

        $this->loader()->load();
        $this->loader()->load(); // re-run

        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, ExternalRef::query()->where('entity_type', 'deal')->count());
        // Activities not duplicated either (ref'd by activity:task:<id>).
        $this->assertSame(2, DB::table('activities')->count());
    }

    public function test_history_is_backdated_not_stamped_today(): void
    {
        $this->fullFixture();

        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();

        // Deal's own created_at is the AMO date (2020-01-01), not the run date.
        $this->assertSame('2020-01-01', $deal->created_at->toDateString());

        // Every stage-history / audit / activity / entity_log row is strictly in
        // the past (no "today" spike).
        $maxStageHistory = DealStageHistory::query()->max('created_at');
        $this->assertLessThan(now()->subYear()->timestamp, strtotime((string) $maxStageHistory));

        $maxLog = DB::table('entity_logs')->max('created_at');
        $this->assertLessThan(now()->subYear()->timestamp, strtotime((string) $maxLog));

        $maxActivity = DB::table('activities')->max('created_at');
        $this->assertLessThan(now()->timestamp, strtotime((string) $maxActivity));
    }

    public function test_unmapped_status_deal_is_gated_not_loaded(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 2000, 'name' => 'Bad', 'price' => 10,
                'status_id' => 999999, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                '_embedded' => ['companies' => [['id' => 5001]], 'contacts' => []],
            ]],
            'companies' => [['id' => 5001, 'name' => 'Co']],
        ]);

        $result = $this->loader()->load();

        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(1, $result['stats']['unmapped_deals']);
        $this->assertNotEmpty($result['conflicts']);
    }

    public function test_won_deal_marks_company_unique_client_and_primary(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 3000, 'name' => 'Won deal', 'price' => 5000,
                'status_id' => 142, // won
                'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 6000, 'is_main' => true]], 'contacts' => []],
                'custom_fields_values' => [
                    ['field_id' => 584603, 'values' => [['value' => 1577836800]]], // signed
                ],
            ]],
            'companies' => [['id' => 6000, 'name' => 'Клиент']],
        ]);

        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $this->assertTrue($deal->is_primary_deal);

        $company = Company::query()->firstOrFail();
        $this->assertNotNull($company->unique_client_since);
    }

    /**
     * BUG 1 regression: the primary flag must be PERSISTED in the DB, not just
     * computed in memory. After a fresh load of a single won deal, the row in
     * `deals` must have is_primary_deal=true (re-read from the database, not the
     * in-memory model the loader held).
     */
    public function test_first_won_deal_persists_is_primary_in_database(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 3100, 'name' => 'Won persist', 'price' => 5000,
                'status_id' => 142, // won
                'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 6100, 'is_main' => true]], 'contacts' => []],
                'custom_fields_values' => [
                    ['field_id' => 584603, 'values' => [['value' => 1577836800]]], // signed
                ],
            ]],
            'companies' => [['id' => 6100, 'name' => 'Клиент Persist']],
        ]);

        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();

        // Re-read the raw column straight from the DB — proves it was persisted,
        // not left true only on the in-memory instance.
        $persisted = (bool) DB::table('deals')->where('id', $deal->id)->value('is_primary_deal');
        $this->assertTrue($persisted, 'is_primary_deal must be persisted on the won deal');
    }

    /**
     * BUG 1 regression (the production shape): TWO won deals on the SAME company.
     * The earlier-signed deal must be the primary one, and the later won deal must
     * NOT carry the flag — even though the later deal was the one that found the
     * company already a unique client. Proves the flag is decoupled from the
     * company stamp and pinned to the earliest-won deal.
     */
    public function test_primary_flag_pins_to_earliest_won_deal_of_company(): void
    {
        $this->writeStaging([
            'leads' => [
                [
                    'id' => 3201, 'name' => 'Earlier won', 'price' => 1000,
                    'status_id' => 142, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    'created_at' => 1577836800,
                    '_embedded' => ['companies' => [['id' => 6200, 'is_main' => true]], 'contacts' => []],
                    'custom_fields_values' => [
                        ['field_id' => 584603, 'values' => [['value' => 1577836800]]], // 2020-01-01 signed
                    ],
                ],
                [
                    'id' => 3202, 'name' => 'Later won', 'price' => 2000,
                    'status_id' => 142, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    'created_at' => 1609459200,
                    '_embedded' => ['companies' => [['id' => 6200, 'is_main' => true]], 'contacts' => []],
                    'custom_fields_values' => [
                        ['field_id' => 584603, 'values' => [['value' => 1609459200]]], // 2021-01-01 signed
                    ],
                ],
            ],
            'companies' => [['id' => 6200, 'name' => 'Двойной клиент']],
        ]);

        $this->loader()->load();

        $earlier = Deal::query()->where('title', 'Earlier won')->firstOrFail();
        $later = Deal::query()->where('title', 'Later won')->firstOrFail();

        $this->assertTrue((bool) DB::table('deals')->where('id', $earlier->id)->value('is_primary_deal'));
        $this->assertFalse((bool) DB::table('deals')->where('id', $later->id)->value('is_primary_deal'));
        $this->assertSame(1, Deal::query()->where('company_id', $earlier->company_id)->where('is_primary_deal', true)->count());
    }

    /**
     * BUG 2 regression: a won deal loaded while its manager had no MGCRM account
     * (owner fell back to the import service user). The manager is then seeded and
     * the loader re-runs. The re-load must RE-RESOLVE the owner to the real user
     * and UPDATE owner_user_id on the existing row — with no duplicate deal.
     */
    public function test_reload_updates_owner_from_fallback_to_real_user(): void
    {
        // AMO user 2810827 → yu.rusakova@macrocrm.ru, NOT seeded yet → owner falls
        // back to import-amo@mgcrm.local on the first load.
        $this->writeStaging([
            'leads' => [[
                'id' => 3300, 'name' => 'Owner reload', 'price' => 4000,
                'status_id' => 142, 'pipeline_id' => 6149857, 'responsible_user_id' => 2810827,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 6300, 'is_main' => true]], 'contacts' => []],
                'custom_fields_values' => [
                    ['field_id' => 584603, 'values' => [['value' => 1577836800]]],
                ],
            ]],
            'companies' => [['id' => 6300, 'name' => 'Owner Co']],
        ]);

        $this->loader()->load();

        $fallback = User::query()->where('email', 'import-amo@mgcrm.local')->firstOrFail();
        $deal = Deal::query()->firstOrFail();
        $this->assertSame($fallback->id, $deal->owner_user_id, 'owner should be the import fallback on first load');

        // Manager appears, re-load (fresh resolver — new loader instance).
        $manager = User::factory()->create(['email' => 'yu.rusakova@macrocrm.ru', 'full_name' => 'Yu Rusakova']);

        $result = $this->loader()->load();

        $deal->refresh();
        $this->assertSame($manager->id, $deal->owner_user_id, 'owner must be re-resolved to the real manager on re-load');
        $this->assertSame(1, Deal::query()->count(), 'no duplicate deal on re-load');
        $this->assertSame(1, ExternalRef::query()->where('entity_type', 'deal')->count());
        $this->assertSame(1, $result['stats']['deals_updated']);
        $this->assertSame(0, $result['stats']['deals_created']);
    }

    /**
     * BUG 2 regression for is_primary_deal: a won deal loaded before its company
     * had any other deals carries the primary flag; re-loading must keep it true
     * on the same row (idempotent, no duplicate) and never lose it.
     */
    public function test_reload_keeps_is_primary_on_existing_deal(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 3400, 'name' => 'Primary reload', 'price' => 6000,
                'status_id' => 142, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 6400, 'is_main' => true]], 'contacts' => []],
                'custom_fields_values' => [
                    ['field_id' => 584603, 'values' => [['value' => 1577836800]]],
                ],
            ]],
            'companies' => [['id' => 6400, 'name' => 'Primary Co']],
        ]);

        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        // Simulate the production defect: the row exists but the flag was never
        // persisted. A re-load must REPAIR it.
        DB::table('deals')->where('id', $deal->id)->update(['is_primary_deal' => false]);
        $this->assertFalse((bool) DB::table('deals')->where('id', $deal->id)->value('is_primary_deal'));

        $this->loader()->load();

        $this->assertTrue(
            (bool) DB::table('deals')->where('id', $deal->id)->value('is_primary_deal'),
            'a re-load must repair is_primary_deal on the existing won deal'
        );
        $this->assertSame(1, Deal::query()->count());
    }

    public function test_no_company_synthesizes_from_primary_contact(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 4000, 'name' => 'Physical person deal', 'price' => 100,
                'status_id' => 53233417, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                '_embedded' => ['companies' => [], 'contacts' => [['id' => 8000, 'is_main' => true]]],
            ]],
            'contacts' => [['id' => 8000, 'name' => 'Сергей Физлицов']],
        ]);

        $result = $this->loader()->load();

        $company = Company::query()->firstOrFail();
        $this->assertSame('Сергей Физлицов (физлицо)', $company->name);
        $this->assertSame(1, $result['stats']['companies_synthetic']);
    }

    // ---- Hardening: never crash on unmapped / malformed AMO data ----

    /**
     * The production crash: a status-change event whose TARGET AMO status has no
     * status_map entry → to_stage_id resolves to null. The history row must be
     * SKIPPED (not a NOT NULL violation), the deal + its other rows untouched, and
     * the unmapped status tallied for the report.
     */
    public function test_unmapped_target_status_skips_history_row_not_crashes(): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => 1100, 'name' => 'Deal with bad event', 'price' => 1000,
                'status_id' => 53233417, // qualification — deal itself is fine
                'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 5100, 'is_main' => true]], 'contacts' => []],
            ]],
            'companies' => [['id' => 5100, 'name' => 'ООО Краевой случай']],
            'events' => [
                ['id' => 'g1', 'type' => 'lead_added', '_lead_id' => 1100, 'created_at' => 1577836800, 'created_by' => 2435437],
                [
                    // value_after points at an UNMAPPED status id (999999).
                    'id' => 'bad1', 'type' => 'lead_status_changed', '_lead_id' => 1100,
                    'created_at' => 1577923200, 'created_by' => 2435437,
                    'value_before' => [['lead_status' => ['id' => 53233417, 'pipeline_id' => 6149857]]],
                    'value_after' => [['lead_status' => ['id' => 999999, 'pipeline_id' => 6149857]]],
                ],
            ],
        ]);

        $result = $this->loader()->load();

        // The deal still loaded.
        $deal = Deal::query()->where('title', 'Deal with bad event')->first();
        $this->assertNotNull($deal);

        // Only the GENESIS history row survives — the bad stage-change is skipped.
        $this->assertSame(1, DealStageHistory::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(0, DealStageHistory::query()->whereNull('to_stage_id')->count());

        // Skip was tallied + reported, the run did not abort.
        $this->assertSame(1, $result['stats']['history_skipped']);
        $this->assertSame(1, $result['stats']['skipped_history:unmapped_target_status']);
        $this->assertSame(1, $result['unmapped']['status']['999999']);
        $this->assertSame(0, $result['stats']['failed_deals']);
    }

    /**
     * A deal whose own status is unmapped is gated (whole deal skipped, logged),
     * but the run continues and the NEXT, good deal still loads. Proves one bad
     * deal never aborts the batch.
     */
    public function test_unmapped_deal_status_is_skipped_and_run_continues(): void
    {
        $this->writeStaging([
            'leads' => [
                [
                    'id' => 1200, 'name' => 'Bad deal', 'price' => 10,
                    'status_id' => 999999, // no status_map entry → critical skip
                    'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    '_embedded' => ['companies' => [['id' => 5200]], 'contacts' => []],
                ],
                [
                    'id' => 1201, 'name' => 'Good deal', 'price' => 20,
                    'status_id' => 53233417, // qualification — fine
                    'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    '_embedded' => ['companies' => [['id' => 5201]], 'contacts' => []],
                ],
            ],
            'companies' => [
                ['id' => 5200, 'name' => 'Co Bad'],
                ['id' => 5201, 'name' => 'Co Good'],
            ],
        ]);

        $result = $this->loader()->load();

        // Bad deal gated, good deal loaded — run did not stop on the first.
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame('Good deal', Deal::query()->firstOrFail()->title);
        $this->assertSame(1, $result['stats']['unmapped_deals']);
        $this->assertSame(1, $result['stats']['skipped_deal:unmapped_status']);
        $this->assertSame(1, $result['unmapped']['status']['999999']);
        $this->assertSame(0, $result['stats']['failed_deals']);
    }

    /**
     * Dry-run on a DIRTY fixture (one unmapped-status deal, one good deal with a
     * bad stage-change event) must: write NOTHING, not abort on the first problem,
     * and return a complete coverage report (would-create counts + skip tallies +
     * unmapped buckets) in a single pass.
     */
    public function test_dry_run_collects_full_report_and_writes_nothing(): void
    {
        $this->writeStaging([
            'leads' => [
                [
                    'id' => 1300, 'name' => 'Dirty unmapped', 'price' => 10,
                    'status_id' => 888888, // unmapped → would skip whole deal
                    'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    '_embedded' => ['companies' => [['id' => 5300]], 'contacts' => []],
                ],
                [
                    'id' => 1301, 'name' => 'Good with bad event', 'price' => 30,
                    'status_id' => 53233417, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                    'created_at' => 1577836800,
                    '_embedded' => ['companies' => [['id' => 5301, 'is_main' => true]], 'contacts' => []],
                ],
            ],
            'companies' => [
                ['id' => 5300, 'name' => 'Co Dirty'],
                ['id' => 5301, 'name' => 'Co Good'],
            ],
            'events' => [
                ['id' => 'g2', 'type' => 'lead_added', '_lead_id' => 1301, 'created_at' => 1577836800, 'created_by' => 2435437],
                [
                    'id' => 'bad2', 'type' => 'lead_status_changed', '_lead_id' => 1301,
                    'created_at' => 1577923200, 'created_by' => 2435437,
                    'value_before' => [['lead_status' => ['id' => 53233417, 'pipeline_id' => 6149857]]],
                    'value_after' => [['lead_status' => ['id' => 777777, 'pipeline_id' => 6149857]]], // unmapped target
                ],
            ],
        ]);

        $result = $this->loader()->load(['dry_run' => true]);

        // 1) NOTHING persisted.
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, DealStageHistory::query()->count());
        $this->assertSame(0, ExternalRef::query()->count());

        // 2) Full collect-and-report in one pass.
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['stats']['deals_created']); // the good deal WOULD load
        $this->assertSame(1, $result['stats']['unmapped_deals']); // the dirty one WOULD skip
        $this->assertSame(1, $result['stats']['history_skipped']); // the bad event WOULD skip
        $this->assertSame(0, $result['stats']['failed_deals']); // no crash anywhere

        // 3) Both unmapped status ids collected with counts (not just the first).
        $this->assertSame(1, $result['unmapped']['status']['888888']);
        $this->assertSame(1, $result['unmapped']['status']['777777']);
    }
}
