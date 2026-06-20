<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Tests\TestCase;

/**
 * The config/amo_migration.php skeleton loads with the expected map keys (the
 * placeholder values are filled before the load phase, outside N7).
 */
class AmoMigrationConfigTest extends TestCase
{
    public function test_config_loads_with_expected_map_keys(): void
    {
        $config = config('amo_migration');

        $this->assertIsArray($config);

        foreach (['pipelines', 'status_map', 'user_map', 'task_type_map', 'note_type_map', 'loss_reason_map'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
    }

    public function test_terminal_statuses_142_won_143_lost(): void
    {
        $statusMap = config('amo_migration.status_map');

        $this->assertSame('won', $statusMap[142]['stage_code']);
        $this->assertSame('lost', $statusMap[143]['stage_code']);
    }

    public function test_fallback_user_email_matches_service_account(): void
    {
        $this->assertSame('import-amo@mgcrm.local', config('amo_migration.fallback_user_email'));
    }

    public function test_pipelines_default_to_rub(): void
    {
        $pipelines = config('amo_migration.pipelines');

        foreach ($pipelines as $pipeline) {
            $this->assertSame('RUB', $pipeline['default_currency']);
        }
    }
}
