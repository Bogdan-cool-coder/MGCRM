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
}
