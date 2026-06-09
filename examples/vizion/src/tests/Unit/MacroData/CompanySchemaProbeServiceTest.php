<?php

namespace Tests\Unit\MacroData;

use App\Models\Company;
use App\Services\MacroData\CompanySchemaProbeService;
use App\Services\MacroData\ConnectionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for CompanySchemaProbeService.
 *
 * Strategy: we run inside the Laravel test environment (sqlite :memory:),
 * but the probe reads from the 'macrodata' connection. In tests we:
 *   1. Override 'database.connections.macrodata' to use a SEPARATE sqlite :memory: DB
 *      (a different in-memory handle via a named file path, not shared with the main DB).
 *   2. Create a temporary `finances_types` table in that connection.
 *   3. Seed test rows and assert probe results.
 *
 * ConnectionService::connect() is mocked so it is a no-op (no real MySQL needed).
 * We do NOT use RefreshDatabase — the main (sqlite :memory:) DB only needs the default
 * migration set run once per test class, and we never touch it from probe tests.
 */
class CompanySchemaProbeServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private CompanySchemaProbeService $service;
    private Company $company;

    /** Unique file-backed sqlite path for the macrodata connection per test. */
    private string $macrodataDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a file-backed sqlite for macrodata so it is isolated from the main
        // in-memory DB and is guaranteed to be clean on each test (we delete the file
        // in tearDown). Using ':memory:' for a second connection would share the same
        // PDO handle only if the connection is purged and re-created — a file path is
        // safer and avoids conflicts with RefreshDatabase's in-memory handle.
        $this->macrodataDbPath = sys_get_temp_dir() . '/probe_test_' . uniqid('', true) . '.sqlite';
        // SQLite requires the file to exist before connecting.
        touch($this->macrodataDbPath);

        config([
            'database.connections.macrodata' => [
                'driver'                  => 'sqlite',
                'database'                => $this->macrodataDbPath,
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        // Purge cached connection so Laravel creates a fresh one with the new config.
        DB::purge('macrodata');

        // Create the temporary MacroData lookup table in the macrodata connection.
        DB::connection('macrodata')->getSchemaBuilder()->create('finances_types', function ($table) {
            $table->integer('id');
            $table->string('types_name')->nullable();
        });

        // Build a fake Company model (no DB row needed for the probe itself).
        $this->company = new Company(['id' => 99]);
        $this->company->setAttribute('id', 99);

        // Mock ConnectionService: connect() is a no-op in unit tests.
        $mockConn = $this->createMock(ConnectionService::class);
        $mockConn->method('connect')->willReturnCallback(static function () {});

        $this->service = new CompanySchemaProbeService($mockConn);
    }

    protected function tearDown(): void
    {
        // Disconnect the macrodata connection and remove the temp file.
        try {
            DB::purge('macrodata');
        } catch (\Throwable) {
            // Ignore — may already be disconnected.
        }
        if ($this->macrodataDbPath && file_exists($this->macrodataDbPath)) {
            @unlink($this->macrodataDbPath);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertRow(int $id, string $typesName): void
    {
        DB::connection('macrodata')->table('finances_types')->insert([
            'id'         => $id,
            'types_name' => $typesName,
        ]);
    }

    private function findMapping(array $result, string $semanticKey): ?array
    {
        foreach ($result['mappings'] as $mapping) {
            if ($mapping['semantic_key'] === $semanticKey) {
                return $mapping;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Basic match
    // -------------------------------------------------------------------------

    public function test_matches_ru_sale_pattern(): void
    {
        $this->insertRow(3884, 'Поступления от продажи недвижимости');

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        $this->assertSame([3884], $mapping['value']);
        $this->assertCount(1, $mapping['candidates']);
        $this->assertSame(3884, $mapping['candidates'][0]['id']);
        $this->assertStringContainsString('RU', $mapping['matched_by']);
    }

    public function test_matches_en_sale_pattern(): void
    {
        $this->insertRow(5001, 'Proceeds from sale of apartments');

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        $this->assertSame([5001], $mapping['value']);
        $this->assertStringContainsString('EN', $mapping['matched_by']);
    }

    public function test_matches_booking_pattern_ru(): void
    {
        $this->insertRow(3886, 'Бронирование квартиры');

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_booking_ids');

        $this->assertNotNull($mapping);
        $this->assertSame([3886], $mapping['value']);
    }

    // -------------------------------------------------------------------------
    // Deduplicate when multiple patterns match the same row
    // -------------------------------------------------------------------------

    public function test_deduplicates_ids_when_multiple_patterns_match_same_row(): void
    {
        // "Продажа недвижимости" matches both '%продажа недвижимости%'
        // and could also match '%продажа%' if it existed — here we test that
        // matching '%поступления от продажи%' and '%продажа недвижимости%' on
        // distinct rows still produces unique IDs.
        $this->insertRow(100, 'Продажа недвижимости');
        $this->insertRow(101, 'Продажа недвижимости объектов'); // matches same pattern subset

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        // IDs must be unique — both rows match, so 2 IDs; no duplicates within the set.
        $this->assertSame(count($mapping['value']), count(array_unique($mapping['value'])));
    }

    public function test_same_id_matched_by_two_patterns_is_deduped(): void
    {
        // Row that matches TWO different RU patterns for sale:
        //   '%продажа недвижимости%'   ← matches
        //   '%доход от продажи%'       ← does NOT match this row
        // But if we have a row matching two patterns, the ID must appear once in value[].
        // We simulate by checking that the total value[] count == candidate count.
        $this->insertRow(200, 'Поступления от продажи: доход от продажи земли');
        // This row matches BOTH '%поступления от продажи%' and '%доход от продажи%'.

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        // ID 200 must appear exactly once in value[], not twice.
        $this->assertSame([200], $mapping['value']);
        $this->assertCount(1, $mapping['candidates']);
    }

    // -------------------------------------------------------------------------
    // Unresolved — zero matches
    // -------------------------------------------------------------------------

    public function test_unresolved_when_no_match(): void
    {
        // Insert a row that does not match any probe pattern.
        $this->insertRow(999, 'Комиссия агента');

        $result = $this->service->probe($this->company);

        $this->assertContains('finance_type_sale_ids', $result['unresolved']);
        $this->assertContains('finance_type_booking_ids', $result['unresolved']);
    }

    public function test_value_is_empty_array_when_no_match(): void
    {
        $this->insertRow(999, 'Комиссия агента');

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        $this->assertSame([], $mapping['value']);
        $this->assertSame([], $mapping['candidates']);
        $this->assertSame('', $mapping['matched_by']);
    }

    public function test_unresolved_empty_when_all_matched(): void
    {
        $this->insertRow(3884, 'Поступления от продажи квартир');
        $this->insertRow(3886, 'Бронирование');

        $result = $this->service->probe($this->company);

        $this->assertSame([], $result['unresolved']);
    }

    // -------------------------------------------------------------------------
    // Empty table
    // -------------------------------------------------------------------------

    public function test_all_unresolved_when_table_is_empty(): void
    {
        // Table exists but has no rows.
        $result = $this->service->probe($this->company);

        $this->assertContains('finance_type_sale_ids', $result['unresolved']);
        $this->assertContains('finance_type_booking_ids', $result['unresolved']);
    }

    // -------------------------------------------------------------------------
    // Mixed: one resolved, one not
    // -------------------------------------------------------------------------

    public function test_one_resolved_one_unresolved(): void
    {
        $this->insertRow(3884, 'Поступления от продажи недвижимости');
        // No booking row.

        $result = $this->service->probe($this->company);

        $this->assertNotContains('finance_type_sale_ids', $result['unresolved']);
        $this->assertContains('finance_type_booking_ids', $result['unresolved']);
    }

    // -------------------------------------------------------------------------
    // Case-insensitivity
    // -------------------------------------------------------------------------

    public function test_case_insensitive_match(): void
    {
        // All-caps name should still match the lowercase pattern.
        $this->insertRow(7001, 'BOOKING OF APARTMENT');

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_booking_ids');

        $this->assertNotNull($mapping);
        $this->assertSame([7001], $mapping['value']);
    }

    // -------------------------------------------------------------------------
    // probed_at is a Carbon instance
    // -------------------------------------------------------------------------

    public function test_probe_returns_carbon_probed_at(): void
    {
        $result = $this->service->probe($this->company);

        $this->assertInstanceOf(\Carbon\Carbon::class, $result['probed_at']);
    }

    // -------------------------------------------------------------------------
    // Multilingual: RU match on one row, EN match on another
    // -------------------------------------------------------------------------

    public function test_ru_and_en_patterns_match_different_rows(): void
    {
        $this->insertRow(4001, 'Proceeds from sale of land');  // EN
        $this->insertRow(4002, 'Доход от продажи квартир');   // RU

        $result  = $this->service->probe($this->company);
        $mapping = $this->findMapping($result, 'finance_type_sale_ids');

        $this->assertNotNull($mapping);
        $this->assertEqualsCanonicalizing([4001, 4002], $mapping['value']);
        // Both locales should appear in matched_by
        $this->assertStringContainsString('EN', $mapping['matched_by']);
        $this->assertStringContainsString('RU', $mapping['matched_by']);
    }
}
