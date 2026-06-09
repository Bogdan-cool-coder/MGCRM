<?php

namespace Tests\Unit\AI;

use App\Services\AI\DataProbeService;
use App\Services\MacroData\ConnectionService;
use Tests\TestCase;

/**
 * Guards the custom-attribute (EAV) probe surface — the entity-validation
 * gate of probeCustomAttributes().
 *
 * The actual EAV SQL (estate_attributes / estate_sells_attr enumeration)
 * needs a live MacroData MySQL connection, so it is exercised by the
 * integration suite, not here. What we CAN assert without a DB is that the
 * entity argument is validated before any connection is attempted: an unknown
 * entity must throw a readable InvalidArgumentException (which the AI tool
 * surfaces back to the model as a structured error) and must NOT reach the
 * connection layer.
 */
class DataProbeCustomAttributesTest extends TestCase
{
    private function makeService(object &$connectSpy): DataProbeService
    {
        // ConnectionService stub that records whether connect() was called.
        $stub = new class($connectSpy) extends ConnectionService {
            public function __construct(private object $spy) {}
            public function connect(\App\Models\Company $company): void
            {
                $this->spy->connected = true;
            }
        };

        return new DataProbeService($stub);
    }

    public function test_unknown_entity_throws_before_connecting(): void
    {
        $spy = (object) ['connected' => false];
        $svc = $this->makeService($spy);
        $company = new \App\Models\Company(['name' => 'X']);

        try {
            $svc->probeCustomAttributes($company, 'bogus_entity');
            $this->fail('Expected InvalidArgumentException for unknown entity');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("bogus_entity", $e->getMessage());
            $this->assertStringContainsString('estate_sell', $e->getMessage());
        }

        $this->assertFalse($spy->connected, 'connect() must not run for an invalid entity');
    }

    public function test_entity_is_normalized_before_validation(): void
    {
        // ' Estate_Sell ' (mixed case + whitespace) is a valid entity once
        // trimmed + lowercased. We can't run the SQL without a DB, so we only
        // assert the validation gate accepts it: connect() should be reached
        // (and the connection stub is a no-op), then the real query fails on
        // the absent MacroData connection — which is fine, that's past the gate.
        $spy = (object) ['connected' => false];
        $svc = $this->makeService($spy);
        $company = new \App\Models\Company(['name' => 'X']);

        try {
            $svc->probeCustomAttributes($company, ' Estate_Sell ');
        } catch (\InvalidArgumentException $e) {
            $this->fail('Valid (normalizable) entity must pass the validation gate: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Any non-validation throwable (e.g. missing DB connection) means we
            // got PAST the entity gate — exactly what this test asserts.
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($spy->connected, 'A valid entity must reach connect()');
    }
}
