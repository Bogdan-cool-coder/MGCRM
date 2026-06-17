<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Enums\CompletionPolicy;
use PHPUnit\Framework\TestCase;

class CompletionPolicyEnumTest extends TestCase
{
    public function test_informational_and_soft_gate_exist(): void
    {
        $cases = CompletionPolicy::cases();
        $values = array_map(fn (CompletionPolicy $p) => $p->value, $cases);

        $this->assertContains('informational', $values);
        $this->assertContains('soft_gate', $values);
        $this->assertCount(2, $cases);
    }

    public function test_from_string(): void
    {
        $this->assertSame(CompletionPolicy::Informational, CompletionPolicy::from('informational'));
        $this->assertSame(CompletionPolicy::SoftGate, CompletionPolicy::from('soft_gate'));
    }
}
