<?php

namespace Tests\Unit\AI;

use App\Services\AI\DataProbeService;
use App\Services\MacroData\ConnectionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the probe-response size reduction (context-overflow mitigation).
 *
 * probe() output is fed straight into the AI context; on report_generation the
 * system prompt is already ~250 KB. Wide tables with long text / serialized
 * JSON columns used to push the prompt past GLM-5.1's window (HTTP 400 code
 * 1261). truncateRowValues() clamps long scalar strings while keeping the row
 * shape (keys, nesting from eager-loaded relations) intact so the AI still sees
 * field names and example value shapes.
 *
 * We test the pure truncation helper directly (no DB) — the row-count cap
 * (limit(3)) is exercised by the integration suite where a real connection
 * exists.
 */
class DataProbeTruncationTest extends TestCase
{
    private function makeService(): DataProbeService
    {
        // ConnectionService is constructor-injected but never touched by the
        // pure truncation helper. Use a no-op stub so we don't open MySQL.
        $stub = new class extends ConnectionService {
            public function __construct() {}
        };

        return new DataProbeService($stub);
    }

    private function truncate(array $row): array
    {
        $svc = $this->makeService();
        $m = new ReflectionMethod($svc, 'truncateRowValues');
        $m->setAccessible(true);

        return $m->invoke($svc, $row);
    }

    public function test_long_string_is_truncated_with_ellipsis(): void
    {
        $long = str_repeat('a', 500);
        $out = $this->truncate(['notes' => $long]);

        // 120 chars + ellipsis marker.
        $this->assertSame(120 + 1, mb_strlen($out['notes']));
        $this->assertStringEndsWith('…', $out['notes']);
    }

    public function test_short_string_and_scalars_pass_through(): void
    {
        $out = $this->truncate([
            'name'   => 'Иванов И.И.',
            'amount' => 1234567,
            'rate'   => 12.5,
            'active' => true,
            'empty'  => null,
        ]);

        $this->assertSame('Иванов И.И.', $out['name']);
        $this->assertSame(1234567, $out['amount']);
        $this->assertSame(12.5, $out['rate']);
        $this->assertTrue($out['active']);
        $this->assertNull($out['empty']);
    }

    public function test_nested_relation_rows_are_truncated_recursively(): void
    {
        $long = str_repeat('z', 300);
        $out = $this->truncate([
            'id'           => 7,
            'estate_sells' => [
                ['geo_flatnum' => 'A-101', 'description' => $long],
            ],
        ]);

        $this->assertSame(7, $out['id']);
        $this->assertSame('A-101', $out['estate_sells'][0]['geo_flatnum']);
        $this->assertSame(120 + 1, mb_strlen($out['estate_sells'][0]['description']));
    }
}
