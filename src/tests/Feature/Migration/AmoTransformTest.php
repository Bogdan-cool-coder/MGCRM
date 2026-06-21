<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Crm\Models\AcquisitionChannel;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Support\AmoReferenceResolver;
use App\Domain\Migration\Transformers\CompanyTransformer;
use App\Domain\Migration\Transformers\ContactTransformer;
use App\Domain\Migration\Transformers\DealTransformer;
use App\Domain\Migration\Transformers\EventTransformer;
use App\Domain\Migration\Transformers\NoteTransformer;
use App\Domain\Migration\Transformers\TaskTransformer;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transform tests — pure AMO row → MGCRM attribute arrays. The maps live in
 * config('amo_migration'); the few FK lookups (stage / channel / user) hit the
 * SQLite :memory: DB, so we seed the minimum reference rows.
 */
class AmoTransformTest extends TestCase
{
    use RefreshDatabase;

    private AmoReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AmoReferenceResolver;
    }

    /**
     * Seed the two AMO pipelines + the stages the tests reference, named/coded to
     * match config('amo_migration').
     */
    private function seedMacroGlobalPipeline(): Pipeline
    {
        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);

        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification']);
        PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'code' => 'success']);
        PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id, 'code' => 'lost']);

        return $pipeline;
    }

    // ---- Deal ----

    public function test_lead_transforms_amount_to_kopecks_and_locks_budget(): void
    {
        $this->seedMacroGlobalPipeline();

        $out = (new DealTransformer($this->resolver))->transform([
            'id' => 100,
            'name' => 'Big deal',
            'price' => 1500,
            'status_id' => 53233417, // macro_global qualification
            'pipeline_id' => 6149857,
            'responsible_user_id' => 999,
        ]);

        $this->assertSame(150000, $out['deal']['amount']); // 1500 RUB × 100
        $this->assertTrue($out['deal']['amount_locked']);
        $this->assertSame('RUB', $out['deal']['currency']);
        $this->assertFalse($out['unmapped_status']);
        $this->assertSame('qualification', $out['stage_code']);
    }

    public function test_won_lead_carries_actual_dates_not_planned(): void
    {
        $this->seedMacroGlobalPipeline();

        // 2023-06-15 12:00 MSK and 2023-07-01.
        $signTs = 1686819600;
        $payTs = 1688158800;

        $out = (new DealTransformer($this->resolver))->transform([
            'id' => 101,
            'price' => 1000,
            'status_id' => 142, // won
            'pipeline_id' => 6149857,
            'custom_fields_values' => [
                ['field_id' => 584603, 'values' => [['value' => $signTs]]],
                ['field_id' => 585395, 'values' => [['value' => $payTs]]],
            ],
        ]);

        $this->assertTrue($out['is_won']);
        $this->assertArrayHasKey('signed_at', $out['deal']);
        $this->assertArrayHasKey('paid_at', $out['deal']);
        $this->assertArrayNotHasKey('expected_sign_date', $out['deal']);
        $this->assertSame('2023-06-15', $out['deal']['signed_at']);
    }

    public function test_open_lead_carries_planned_dates_not_actual(): void
    {
        $this->seedMacroGlobalPipeline();

        $out = (new DealTransformer($this->resolver))->transform([
            'id' => 102,
            'price' => 0,
            'status_id' => 53233417, // qualification (open)
            'pipeline_id' => 6149857,
            'custom_fields_values' => [
                ['field_id' => 584603, 'values' => [['value' => 1686819600]]],
            ],
        ]);

        $this->assertFalse($out['is_won']);
        $this->assertArrayHasKey('expected_sign_date', $out['deal']);
        $this->assertArrayNotHasKey('signed_at', $out['deal']);
        $this->assertSame(0, $out['deal']['amount']); // price 0 → 0, never null
    }

    public function test_unmapped_status_is_flagged(): void
    {
        $this->seedMacroGlobalPipeline();

        $out = (new DealTransformer($this->resolver))->transform([
            'id' => 103,
            'price' => 10,
            'status_id' => 999999, // not in status_map
            'pipeline_id' => 6149857,
        ]);

        $this->assertTrue($out['unmapped_status']);
        $this->assertNull($out['deal']['stage_id']);
    }

    public function test_perpetual_license_read_from_custom_field(): void
    {
        $this->seedMacroGlobalPipeline();

        $out = (new DealTransformer($this->resolver))->transform([
            'id' => 104,
            'price' => 10,
            'status_id' => 53233417,
            'pipeline_id' => 6149857,
            'custom_fields_values' => [
                ['field_id' => 709732, 'values' => [['value' => '1']]],
                ['field_id' => 748860, 'values' => [['value' => 'M 2', 'enum_id' => 1204192]]],
            ],
        ]);

        $this->assertTrue($out['deal']['perpetual_license']);
        $this->assertSame('M 2', $out['deal']['extra_fields']['amo_category']);
    }

    // ---- Company ----

    public function test_company_maps_country_to_iso_and_keeps_region_label(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 500, 'name' => 'ООО Ромашка'],
            ['custom_fields_values' => [
                ['field_id' => 711078, 'values' => [['value' => 'г. Москва', 'enum_id' => 1188488]]],
                ['field_id' => 709194, 'values' => [['value' => '7701234567']]],
            ]],
        );

        $this->assertSame('ru', $out['company']['country_code']);
        $this->assertSame('г. Москва', $out['company']['extra_fields']['amo_region']);
        $this->assertSame('ИНН', $out['company']['tax_id_label']); // ru → ИНН
        $this->assertSame('7701234567', $out['company']['tax_id']);
        $this->assertSame('ООО Ромашка', $out['company']['name']);
    }

    public function test_company_tax_id_noise_is_dropped(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 501, 'name' => 'X'],
            ['custom_fields_values' => [
                ['field_id' => 709194, 'values' => [['value' => '-']]],
            ]],
        );

        $this->assertNull($out['company']['tax_id']);
    }

    public function test_company_takes_first_mappable_specialization(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 502, 'name' => 'Y', 'custom_fields_values' => [
                // 1138200 (промышленное → null), then 1138196 (developer).
                ['field_id' => 709546, 'values' => [
                    ['enum_id' => 1138200],
                    ['enum_id' => 1138196],
                ]],
            ]],
            [],
        );

        $this->assertSame(CompanySpecialization::Developer->value, $out['company']['specialization']);
    }

    public function test_company_resolves_acquisition_channel_by_name(): void
    {
        AcquisitionChannel::query()->create(['name' => 'Холодный звонок', 'sort_order' => 1, 'is_active' => true]);

        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 503, 'name' => 'Z', 'custom_fields_values' => [
                ['field_id' => 708366, 'values' => [['enum_id' => 1136342]]], // → 'Холодный звонок'
            ]],
            [],
        );

        $this->assertNotNull($out['company']['acquisition_channel_id']);
    }

    public function test_company_synthesized_from_contact_when_no_company(): void
    {
        $out = (new CompanyTransformer($this->resolver))
            ->transformFromContact(['id' => 1, 'created_by' => 5], ['name' => 'Иван Петров']);

        $this->assertSame('Иван Петров (физлицо)', $out['company']['name']);
        $this->assertTrue($out['company']['extra_fields']['amo_synthetic_company']);
    }

    public function test_company_synthesized_fallback_when_no_contact(): void
    {
        $out = (new CompanyTransformer($this->resolver))
            ->transformFromContact(['id' => 1], null);

        $this->assertSame('Без контрагента (импорт)', $out['company']['name']);
    }

    // ---- Contact ----

    public function test_contact_fans_out_multi_channels_and_denormalizes_first(): void
    {
        $out = (new ContactTransformer($this->resolver))->transform([
            'id' => 200,
            'name' => 'Пётр',
            'custom_fields_values' => [
                ['field_code' => 'PHONE', 'values' => [
                    ['value' => '+79990001122', 'enum_code' => 'WORK'],
                    ['value' => '+79990003344', 'enum_code' => 'MOB'],
                ]],
                ['field_code' => 'EMAIL', 'values' => [
                    ['value' => 'p@example.com', 'enum_code' => 'WORK'],
                ]],
            ],
        ]);

        $this->assertSame('Пётр', $out['contact']['full_name']);
        $this->assertSame('+79990001122', $out['contact']['phone']); // first phone denorm
        $this->assertSame('p@example.com', $out['contact']['email']);
        $this->assertCount(3, $out['channels']); // 2 phones + 1 email
        $this->assertTrue($out['channels'][0]['is_primary_for_channel']);
    }

    // ---- Event ----

    public function test_event_classifies_genesis_stage_and_data_change(): void
    {
        $transformer = new EventTransformer($this->resolver);

        $genesis = $transformer->transform(['id' => 'e1', 'type' => 'lead_added', '_lead_id' => 100, 'created_at' => 1600000000, 'created_by' => 7]);
        $this->assertSame('genesis', $genesis['class']);
        $this->assertSame(100, $genesis['amo_lead_id']);

        $stage = $transformer->transform([
            'id' => 'e2', 'type' => 'lead_status_changed', '_lead_id' => 100, 'created_at' => 1600000100, 'created_by' => 7,
            'value_before' => [['lead_status' => ['id' => 53233417, 'pipeline_id' => 6149857]]],
            'value_after' => [['lead_status' => ['id' => 142, 'pipeline_id' => 6149857]]],
        ]);
        $this->assertSame('stage_change', $stage['class']);
        $this->assertSame(53233417, $stage['amo_status_from']);
        $this->assertSame(142, $stage['amo_status_to']);

        $data = $transformer->transform([
            'id' => 'e3', 'type' => 'sale_field_changed', '_lead_id' => 100, 'created_at' => 1600000200, 'created_by' => 7,
            'value_before' => [['sale' => 1000]],
            'value_after' => [['sale' => 2000]],
        ]);
        $this->assertSame('data_change', $data['class']);
        $this->assertSame('amount', $data['field']);
        $this->assertSame('2000', $data['new_value']);
    }

    public function test_event_ignores_unknown_types(): void
    {
        $out = (new EventTransformer($this->resolver))->transform(['id' => 'e', 'type' => 'common_note_added', '_lead_id' => 1]);

        $this->assertSame('ignore', $out['class']);
    }

    // ---- Task / Note ----

    public function test_task_completed_lands_done_and_closed(): void
    {
        $out = (new TaskTransformer($this->resolver))->transform([
            'id' => 9001,
            'entity_type' => 'leads',
            'entity_id' => 100,
            'task_type_id' => 1, // call
            'text' => 'Перезвонить',
            'complete_till' => 1600000000,
            'is_completed' => true,
            'result' => ['text' => 'Дозвонился'],
            'created_at' => 1599990000,
        ]);

        $this->assertSame('call', $out['activity']['kind']);
        $this->assertTrue($out['activity']['is_closed']);
        $this->assertSame('done', $out['activity']['status']);
        $this->assertSame('Дозвонился', $out['activity']['result_text']);
        $this->assertSame(100, $out['amo_lead_id']);
    }

    public function test_note_call_maps_to_call_and_skip_drops(): void
    {
        $note = (new NoteTransformer($this->resolver))->transform([
            'id' => 'n1', '_lead_id' => 100, 'note_type' => 'call_in',
            'params' => ['text' => 'Входящий'], 'created_at' => 1599990000,
        ]);
        $this->assertFalse($note['skip']);
        $this->assertSame('call', $note['activity']['kind']);
        $this->assertTrue($note['activity']['is_closed']);

        $geo = (new NoteTransformer($this->resolver))->transform([
            'id' => 'n2', '_lead_id' => 100, 'note_type' => 'geolocation',
        ]);
        $this->assertTrue($geo['skip']);
    }
}
