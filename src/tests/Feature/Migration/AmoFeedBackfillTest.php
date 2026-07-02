<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill tests — `migration:amo-feed-backfill` rewrites already-imported feed
 * rows that still hold raw AMO custom_field_value JSON into readable RU text,
 * without re-running the import. Reproduces the deal-70 symptom on raw rows.
 */
class AmoFeedBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification']);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);
        $this->dealId = (int) $deal->id;
    }

    /** The exact deal-70 raw payload, as the pre-fix ETL stored it. */
    private function rawFieldJson(string $text, int $fieldId = 77284, int $enumId = 706692): string
    {
        return (string) json_encode(
            [['custom_field_value' => ['field_id' => $fieldId, 'field_type' => 4, 'enum_id' => $enumId, 'text' => $text]]],
            JSON_UNESCAPED_UNICODE,
        );
    }

    private function insertAudit(string $field, ?string $old, ?string $new): int
    {
        return (int) DB::table('deal_audits')->insertGetId([
            'deal_id' => $this->dealId,
            'user_id' => null,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'created_at' => '2023-01-01 00:00:00',
        ]);
    }

    private function insertActivity(string $body): int
    {
        return (int) DB::table('activities')->insertGetId([
            'kind' => 'note',
            'target_type' => 'deal',
            'target_id' => $this->dealId,
            'title' => 'Заметка',
            'body' => $body,
            'status' => 'done',
            'is_closed' => true,
            'progress_pct' => 100,
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]);
    }

    public function test_backfill_rewrites_raw_audit_json_to_readable_text(): void
    {
        $id = $this->insertAudit(
            'extra_fields.amo_cf_77284',
            $this->rawFieldJson('3. schedule a meeting'),
            $this->rawFieldJson('4.1 walking'),
        );

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();

        $row = DB::table('deal_audits')->where('id', $id)->first();

        // Field name humanised (77284 is not in field_name_map → generic "Поле").
        $this->assertSame('Поле', $row->field);
        $this->assertSame('3. schedule a meeting', $row->old_value);
        $this->assertSame('4.1 walking', $row->new_value);

        foreach ([$row->field, $row->old_value, $row->new_value] as $val) {
            $this->assertStringNotContainsString('{', (string) $val);
            $this->assertStringNotContainsString('custom_field_value', (string) $val);
            $this->assertStringNotContainsString('enum_id', (string) $val);
        }
    }

    public function test_backfill_uses_field_name_map_when_known(): void
    {
        // field_id 711078 is in field_name_map => "Регион".
        $id = $this->insertAudit(
            'extra_fields.amo_cf_711078',
            null,
            $this->rawFieldJson('г. Москва', fieldId: 711078, enumId: 1188488),
        );

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();

        $row = DB::table('deal_audits')->where('id', $id)->first();

        $this->assertSame('Регион', $row->field);
        $this->assertSame('г. Москва', $row->new_value);
    }

    public function test_backfill_rewrites_activity_body(): void
    {
        $id = $this->insertActivity($this->rawFieldJson('Расписание (calendar)'));

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();

        $body = (string) DB::table('activities')->where('id', $id)->value('body');

        $this->assertSame('Расписание (calendar)', $body);
        $this->assertStringNotContainsString('custom_field_value', $body);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $raw = $this->rawFieldJson('4.1 walking');
        $id = $this->insertAudit('extra_fields.amo_cf_77284', null, $raw);

        $this->artisan('migration:amo-feed-backfill --dry-run')->assertSuccessful();

        $this->assertSame($raw, DB::table('deal_audits')->where('id', $id)->value('new_value'));
    }

    public function test_backfill_is_idempotent(): void
    {
        $id = $this->insertAudit('extra_fields.amo_cf_77284', null, $this->rawFieldJson('4.1 walking'));

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();
        $afterFirst = DB::table('deal_audits')->where('id', $id)->value('new_value');

        // Second run must find nothing to change and leave the row untouched.
        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();
        $afterSecond = DB::table('deal_audits')->where('id', $id)->value('new_value');

        $this->assertSame('4.1 walking', $afterFirst);
        $this->assertSame($afterFirst, $afterSecond);
    }

    public function test_clean_rows_are_left_untouched(): void
    {
        // An already-readable audit must not be rewritten.
        $id = $this->insertAudit('amount', '1000', '2000');

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();

        $row = DB::table('deal_audits')->where('id', $id)->first();
        $this->assertSame('amount', $row->field);
        $this->assertSame('1000', $row->old_value);
        $this->assertSame('2000', $row->new_value);
    }

    public function test_unparseable_json_collapses_to_placeholder_not_json(): void
    {
        // A value with the raw markers but no readable text inside.
        $id = $this->insertAudit(
            'extra_fields.amo_cf_77284',
            null,
            (string) json_encode([['custom_field_value' => ['field_id' => 77284, 'enum_id' => 9]]], JSON_UNESCAPED_UNICODE),
        );

        $this->artisan('migration:amo-feed-backfill')->assertSuccessful();

        $new = (string) DB::table('deal_audits')->where('id', $id)->value('new_value');
        $this->assertSame('значение изменено', $new);
        $this->assertStringNotContainsString('{', $new);
    }

    public function test_deal_filter_scopes_the_backfill(): void
    {
        $otherPipeline = Pipeline::factory()->create(['name' => 'Other']);
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $otherPipeline->id, 'code' => 'q']);
        $otherDeal = Deal::factory()->create(['pipeline_id' => $otherPipeline->id, 'stage_id' => $otherStage->id]);

        $mine = $this->insertAudit('extra_fields.amo_cf_77284', null, $this->rawFieldJson('mine'));
        $theirs = (int) DB::table('deal_audits')->insertGetId([
            'deal_id' => $otherDeal->id,
            'user_id' => null,
            'field' => 'extra_fields.amo_cf_77284',
            'old_value' => null,
            'new_value' => $this->rawFieldJson('theirs'),
            'created_at' => '2023-01-01 00:00:00',
        ]);

        $this->artisan("migration:amo-feed-backfill --deal={$this->dealId}")->assertSuccessful();

        $this->assertSame('mine', DB::table('deal_audits')->where('id', $mine)->value('new_value'));
        // The other deal's raw row stays raw (out of scope).
        $this->assertStringContainsString('custom_field_value', (string) DB::table('deal_audits')->where('id', $theirs)->value('new_value'));
    }
}
