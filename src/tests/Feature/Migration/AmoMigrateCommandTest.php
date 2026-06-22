<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Command-surface tests for `php artisan amo:migrate`. No live AMO (Http::fake),
 * no DB. Validates the safety guards (phase / token) and the --only selection.
 */
class AmoMigrateCommandTest extends TestCase
{
    private string $relative;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relative = 'amo-migration-test-'.uniqid();
        config([
            'amo_migration.api.staging_path' => $this->relative,
            'amo_migration.api.token' => 'test-token',
            'amo_migration.api.rate_limit_rps' => 1000,
            'amo_migration.api.base_url' => 'https://macro.amocrm.ru/api/v4',
            'amo_migration.api.retry' => ['max_attempts' => 3, 'base_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        $dir = storage_path($this->relative);

        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_unknown_phase_fails(): void
    {
        $this->artisan('amo:migrate', ['phase' => 'frobnicate'])
            ->expectsOutputToContain('Unknown phase')
            ->assertFailed();
    }

    public function test_load_without_staging_fails_gracefully(): void
    {
        // No leads.jsonl in the (fresh, empty) staging dir → load refuses.
        $this->artisan('amo:migrate', ['phase' => 'load'])
            ->expectsOutputToContain('run `amo:migrate extract` first')
            ->assertFailed();
    }

    public function test_missing_token_fails(): void
    {
        config(['amo_migration.api.token' => null]);

        $this->artisan('amo:migrate', ['phase' => 'extract'])
            ->expectsOutputToContain('AMO_MIGRATION_TOKEN is not set')
            ->assertFailed();
    }

    public function test_only_runs_selected_extractor(): void
    {
        Http::fake([
            'macro.amocrm.ru/*' => Http::response([
                '_embedded' => ['leads' => [['id' => 1, 'name' => 'A', '_embedded' => []]]],
                '_links' => [],
            ], 200),
        ]);

        $this->artisan('amo:migrate', ['phase' => 'extract', '--only' => 'leads', '--limit' => 1])
            ->assertSuccessful();

        // Only the leads endpoint was hit (no contacts/companies/tasks/events).
        Http::assertSent(fn ($request) => str_contains($request->url(), '/leads'));
    }
}
