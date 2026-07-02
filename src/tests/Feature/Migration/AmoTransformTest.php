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

    public function test_company_reads_contact_fields_off_the_company_object(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 510, 'name' => 'ООО Связь', 'custom_fields_values' => [
                // Phone (multitext): WORK is primary, the rest fan out into channels.
                ['field_id' => 2709, 'values' => [
                    ['value' => '+74950000000', 'enum_code' => 'WORK'],
                    ['value' => '+74950001111', 'enum_code' => 'OTHER'],
                ]],
                // Email (multitext): single value.
                ['field_id' => 2711, 'values' => [
                    ['value' => 'info@svyaz.ru', 'enum_code' => 'WORK'],
                ]],
                // Website (url, single).
                ['field_id' => 2713, 'values' => [['value' => 'https://svyaz.ru']]],
                // Address (textarea, single).
                ['field_id' => 2717, 'values' => [['value' => 'г. Москва, ул. Связи, 1']]],
            ]],
            [], // lead — no geo/tax here
        );

        $this->assertSame('+74950000000', $out['company']['phone']); // WORK → primary denorm
        $this->assertSame('info@svyaz.ru', $out['company']['email']);
        $this->assertSame('https://svyaz.ru', $out['company']['website']);
        $this->assertSame('г. Москва, ул. Связи, 1', $out['company']['address']);

        // Extra phones fan out into channels (not stashed in extra_fields).
        $this->assertArrayNotHasKey('amo_company_phones', $out['company']['extra_fields']);
        $this->assertArrayNotHasKey('amo_company_emails', $out['company']['extra_fields']);

        // channels: 2 phones + 1 email + 1 website = 4 rows.
        $this->assertCount(4, $out['channels']);
        $phones = array_filter($out['channels'], fn (array $c) => $c['channel_type'] === 'phone');
        $this->assertCount(2, $phones);
        $primaryPhone = array_values(array_filter($phones, fn (array $c) => $c['is_primary_for_channel']))[0];
        $this->assertSame('+74950000000', $primaryPhone['value']);
    }

    public function test_company_contact_fields_default_to_null_when_absent(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 511, 'name' => 'Пустышка'],
            [],
        );

        $this->assertNull($out['company']['phone']);
        $this->assertNull($out['company']['email']);
        $this->assertNull($out['company']['website']);
        $this->assertNull($out['company']['address']);
        $this->assertSame([], $out['channels']);
    }

    public function test_company_phone_primary_falls_back_to_first_without_work_code(): void
    {
        $out = (new CompanyTransformer($this->resolver))->transform(
            ['id' => 512, 'name' => 'Без WORK', 'custom_fields_values' => [
                ['field_id' => 2709, 'values' => [
                    ['value' => '+70000000001', 'enum_code' => 'MOB'],
                    ['value' => '+70000000002', 'enum_code' => 'OTHER'],
                ]],
            ]],
            [],
        );

        $this->assertSame('+70000000001', $out['company']['phone']); // first when no WORK
        // Both phones fan out into channels (not stashed in extra_fields).
        $this->assertArrayNotHasKey('amo_company_phones', $out['company']['extra_fields']);
        $this->assertCount(2, $out['channels']);
        $this->assertTrue($out['channels'][0]['is_primary_for_channel']); // first is primary
        $this->assertFalse($out['channels'][1]['is_primary_for_channel']);
    }

    public function test_company_synthesized_from_contact_when_no_company(): void
    {
        $out = (new CompanyTransformer($this->resolver))
            ->transformFromContact(['id' => 1, 'created_by' => 5], ['name' => 'Иван Петров']);

        $this->assertSame('Иван Петров (физлицо)', $out['company']['name']);
        $this->assertTrue($out['company']['extra_fields']['amo_synthetic_company']);
        // Synthetic company has no AMO contact fields → no channels.
        $this->assertSame([], $out['channels']);
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

    public function test_contact_reads_position_from_select_with_text_fallback(): void
    {
        // Select 583865 present → its value (the label) wins.
        $out = (new ContactTransformer($this->resolver))->transform([
            'id' => 201,
            'name' => 'Анна',
            'custom_fields_values' => [
                ['field_id' => 583865, 'values' => [['value' => 'Директор', 'enum_id' => 555]]],
                ['field_id' => 2707, 'values' => [['value' => 'Игнорируемый текст']]],
            ],
        ]);

        $this->assertSame('Директор', $out['contact']['position']);
    }

    public function test_contact_position_falls_back_to_text_when_select_empty(): void
    {
        $out = (new ContactTransformer($this->resolver))->transform([
            'id' => 202,
            'name' => 'Борис',
            'custom_fields_values' => [
                ['field_id' => 2707, 'values' => [['value' => 'Главный инженер']]],
            ],
        ]);

        $this->assertSame('Главный инженер', $out['contact']['position']);
    }

    public function test_contact_acquisition_channel_is_always_null(): void
    {
        // Even if a 708366-shaped value is present on the contact (it never is in
        // AMO), the contact channel is set by hand in MGCRM, not by the import.
        $out = (new ContactTransformer($this->resolver))->transform([
            'id' => 203,
            'name' => 'Виктор',
            'custom_fields_values' => [
                ['field_id' => 708366, 'values' => [['enum_id' => 1136342]]],
            ],
        ]);

        $this->assertNull($out['contact']['acquisition_channel_id']);
        $this->assertNull($out['contact']['position']);
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

    public function test_custom_field_value_change_renders_readable_labels_not_json(): void
    {
        // The exact shape AMO returns for a select-field change (the deal-70 bug):
        // value_after nests the enum label inside a `custom_field_value` wrapper.
        $out = (new EventTransformer($this->resolver))->transform([
            'id' => 'e10',
            'type' => 'custom_field_711078_value_changed',
            '_lead_id' => 100,
            'created_at' => 1600000300,
            'created_by' => 7,
            'value_before' => [['custom_field_value' => [
                'field_id' => 711078, 'field_type' => 4, 'enum_id' => 1188594, 'text' => 'г. Санкт-Петербург',
            ]]],
            'value_after' => [['custom_field_value' => [
                'field_id' => 711078, 'field_type' => 4, 'enum_id' => 1188488, 'text' => 'г. Москва',
            ]]],
        ]);

        $this->assertSame('data_change', $out['class']);
        $this->assertSame('Регион', $out['field']); // human field name, not amo_cf_711078
        $this->assertSame('г. Санкт-Петербург', $out['old_value']);
        $this->assertSame('г. Москва', $out['new_value']);

        // Hard guarantee: no raw-JSON tokens leaked into any stored value.
        foreach (['field', 'old_value', 'new_value'] as $key) {
            $this->assertStringNotContainsString('{', (string) $out[$key]);
            $this->assertStringNotContainsString('custom_field_value', (string) $out[$key]);
            $this->assertStringNotContainsString('enum_id', (string) $out[$key]);
        }
    }

    public function test_custom_field_value_change_without_name_map_still_readable(): void
    {
        // A field_id with no field_name_map entry → generic name, but the VALUE
        // text is still rendered (never JSON).
        $out = (new EventTransformer($this->resolver))->transform([
            'id' => 'e11',
            'type' => 'custom_field_999999_value_changed',
            '_lead_id' => 100,
            'value_after' => [['custom_field_value' => [
                'field_id' => 999999, 'enum_id' => 706692, 'text' => 'Расписание (calendar)',
            ]]],
        ]);

        $this->assertSame('Поле', $out['field']);
        $this->assertSame('Расписание (calendar)', $out['new_value']);
        $this->assertNull($out['old_value']);
        $this->assertStringNotContainsString('{', (string) $out['new_value']);
    }

    public function test_scalar_data_change_never_emits_json_for_unknown_shape(): void
    {
        // A non-custom-field data-change whose value block matches no known key
        // must resolve to null, never a json_encode() dump.
        $out = (new EventTransformer($this->resolver))->transform([
            'id' => 'e12',
            'type' => 'entity_tag_added',
            '_lead_id' => 100,
            'value_after' => [['tag' => ['id' => 5, 'name' => 'VIP']]],
        ]);

        $this->assertSame('data_change', $out['class']);
        // 'tag' is not a known flat key and carries no custom_field_value wrapper.
        $this->assertNull($out['new_value']);
        $this->assertStringNotContainsString('{', (string) ($out['new_value'] ?? ''));
    }

    public function test_note_with_field_change_payload_renders_label_not_json(): void
    {
        // Defensive: a field-change payload that arrived shaped as a note body.
        $note = (new NoteTransformer($this->resolver))->transform([
            'id' => 'n3', '_lead_id' => 100, 'note_type' => 'service_message',
            'params' => ['custom_field_value' => ['field_id' => 711078, 'enum_id' => 1188488, 'text' => 'г. Москва']],
            'created_at' => 1599990000,
        ]);

        $this->assertFalse($note['skip']);
        $this->assertSame('г. Москва', $note['activity']['body']);
        $this->assertStringNotContainsString('custom_field_value', (string) $note['activity']['body']);
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
