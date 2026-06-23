<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Crm\Enums\CompanySpecialization;
use Tests\TestCase;

/**
 * config/amo_migration.php carries the filled terminal maps for the AMO import.
 * These tests gate completeness: every status of both funnels, the key countries,
 * the spec/channel targets and the task-type allowlist must resolve.
 */
class AmoMigrationConfigTest extends TestCase
{
    public function test_config_loads_with_expected_map_keys(): void
    {
        $config = config('amo_migration');

        $this->assertIsArray($config);

        foreach ([
            'pipelines', 'status_map', 'user_map', 'fallback_user_email',
            'country_map', 'tax_id_label_map', 'specialization_map', 'channel_map',
            'task_type_map', 'task_type_default', 'note_type_map', 'loss_reason_map',
        ] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
    }

    public function test_terminal_statuses_142_won_143_lost(): void
    {
        $statusMap = config('amo_migration.status_map');

        $this->assertSame('success', $statusMap[142]['stage_code']);
        $this->assertSame('lost', $statusMap[143]['stage_code']);
        // Terminals are funnel-agnostic: pipeline_code null = keep the deal's own funnel.
        $this->assertNull($statusMap[142]['pipeline_code']);
        $this->assertNull($statusMap[143]['pipeline_code']);
    }

    public function test_status_map_covers_every_status_of_both_funnels(): void
    {
        $statusMap = config('amo_migration.status_map');

        // 13 non-terminal stages of MACRO Global (6149857) — incl. the deleted
        // stage 53233413 that folds into 'schedule'.
        $macroGlobal = [
            53169821, 55884061, 53169825, 53169829, 53233417, 53233413, 53233425,
            83123365, 53233429, 53233421, 53169833, 83123369, 53233433,
        ];
        // 11 non-terminal stages of MACRO AI Global (10915373).
        $macroAiGlobal = [
            85848393, 86443005, 85848397, 85868509, 85848401, 85868513,
            85868517, 85868521, 85848405, 85868525, 85868529,
        ];

        foreach ($macroGlobal as $id) {
            $this->assertSame('macro_global', $statusMap[$id]['pipeline_code'], "status {$id}");
            $this->assertNotNull($statusMap[$id]['stage_code']);
        }
        foreach ($macroAiGlobal as $id) {
            $this->assertSame('macro_ai_global', $statusMap[$id]['pipeline_code'], "status {$id}");
            $this->assertNotNull($statusMap[$id]['stage_code']);
        }

        // 24 non-terminal (incl. deleted MACRO Global 53233413) + 2 shared
        // terminals = 26 keys.
        $this->assertCount(26, $statusMap);
    }

    public function test_deleted_macro_global_stage_53233413_maps_to_schedule(): void
    {
        $statusMap = config('amo_migration.status_map');

        $this->assertArrayHasKey(53233413, $statusMap);
        $this->assertSame('macro_global', $statusMap[53233413]['pipeline_code']);
        $this->assertSame('schedule', $statusMap[53233413]['stage_code']);
    }

    public function test_pipeline_codes_in_status_map_resolve_to_a_configured_pipeline(): void
    {
        $pipelines = config('amo_migration.pipelines');

        foreach (config('amo_migration.status_map') as $entry) {
            if ($entry['pipeline_code'] === null) {
                continue; // terminal: deal's own funnel
            }
            $this->assertArrayHasKey($entry['pipeline_code'], $pipelines);
        }
    }

    public function test_fallback_user_email_matches_service_account(): void
    {
        $this->assertSame('import-amo@mgcrm.local', config('amo_migration.fallback_user_email'));
    }

    public function test_user_map_covers_all_44_users_with_valid_emails(): void
    {
        $userMap = config('amo_migration.user_map');

        $this->assertCount(44, $userMap);
        foreach ($userMap as $amoId => $email) {
            $this->assertIsInt($amoId);
            $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), "bad email for {$amoId}");
        }
    }

    public function test_user_map_2351116_points_to_byadykin_macroglobal(): void
    {
        // This AMO user is a real MGCRM user — a re-load attaches their deals to
        // the seeded macroglobaltech.com account.
        $this->assertSame(
            'b.yadykin@macroglobaltech.com',
            config('amo_migration.user_map.2351116'),
        );
    }

    public function test_pipelines_default_to_rub(): void
    {
        foreach (config('amo_migration.pipelines') as $pipeline) {
            $this->assertSame('RUB', $pipeline['default_currency']);
        }
    }

    public function test_country_map_collapses_rf_regions_and_maps_key_countries(): void
    {
        $countryMap = config('amo_migration.country_map');

        // 113 enum options: 86 RF regions, 26 foreign, 1 null ("Иное государство").
        $this->assertCount(113, $countryMap);
        $this->assertSame(86, count(array_keys($countryMap, 'ru', true)));
        $this->assertNull($countryMap[1188638]); // Иное государство

        // Key countries resolve to ISO alpha-2.
        $expected = [
            1188640 => 'uz', 1188642 => 'kz', 1188664 => 'ae', 1191874 => 'ge',
            1191876 => 'kg', 1192284 => 'tr', 1193048 => 'by', 1193940 => 'am',
        ];
        foreach ($expected as $id => $iso) {
            $this->assertSame($iso, $countryMap[$id], "country {$id}");
        }
    }

    public function test_tax_id_label_map_has_per_country_labels(): void
    {
        $labels = config('amo_migration.tax_id_label_map');

        $this->assertSame('ИНН', $labels['ru']);
        $this->assertSame('БИН', $labels['kz']);
        $this->assertSame('TRN', $labels['ae']);
        $this->assertSame('УНП', $labels['by']);
        // Unlisted country => no label (UI shows generic "Tax ID").
        $this->assertArrayNotHasKey('us', $labels);
    }

    public function test_specialization_map_targets_are_valid_enum_values_or_null(): void
    {
        $map = config('amo_migration.specialization_map');
        $valid = array_map(static fn (CompanySpecialization $c): string => $c->value, CompanySpecialization::cases());

        $this->assertCount(12, $map);
        foreach ($map as $enumId => $target) {
            if ($target === null) {
                continue; // intentional skip — no MGCRM target
            }
            $this->assertContains($target, $valid, "spec {$enumId} target");
        }

        // Spot-check the explicit decisions.
        $this->assertSame('developer', $map[1138196]);
        $this->assertSame('real_estate_agency', $map[1138206]);
        $this->assertSame('partner', $map[1189808]);
        $this->assertNull($map[1138216]); // Другое/недевелопмент -> no target
    }

    public function test_channel_map_targets_are_one_of_the_seven_seeded_channels(): void
    {
        $map = config('amo_migration.channel_map');
        $seeded = [
            'Рекомендации клиентов', 'Рекомендации партнёров', 'Входящий запрос',
            'Холодный звонок', 'Выставка', 'Соцсети', 'Другое',
        ];

        $this->assertCount(17, $map);
        foreach ($map as $enumId => $target) {
            $this->assertContains($target, $seeded, "channel {$enumId} target");
        }

        $this->assertSame('Холодный звонок', $map[1136342]);
        $this->assertSame('Выставка', $map[1190540]);
        $this->assertSame('Другое', $map[1136340]);
    }

    public function test_task_type_map_allowlist_and_default(): void
    {
        $map = config('amo_migration.task_type_map');

        $this->assertSame('task', config('amo_migration.task_type_default'));
        $this->assertSame('call', $map[1]);          // Связаться
        $this->assertSame('call', $map[2783037]);    // 1.2 Звонок
        $this->assertSame('meeting', $map[2]);       // Встреча
        $this->assertSame('meeting', $map[1887817]); // 4.1 MeetingDone
        // An out-of-allowlist type is absent => ETL falls back to default.
        $this->assertArrayNotHasKey(2474448, $map);  // "Задача"
    }

    public function test_note_type_map_routes_calls_and_skips_geolocation(): void
    {
        $map = config('amo_migration.note_type_map');

        $this->assertSame('call', $map['call_in']);
        $this->assertSame('call', $map['call_out']);
        $this->assertSame('note', $map['common']);
        $this->assertSame('skip', $map['geolocation']);
    }

    public function test_loss_reason_map_is_empty_by_decision(): void
    {
        $this->assertSame([], config('amo_migration.loss_reason_map'));
    }
}
