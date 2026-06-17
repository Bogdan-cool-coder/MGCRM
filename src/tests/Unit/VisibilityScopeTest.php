<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisibilityScopeTest extends TestCase
{
    #[DataProvider('roleScopeProvider')]
    public function test_for_role_maps_role_to_default_scope(?string $role, VisibilityScope $expected): void
    {
        $this->assertSame($expected, VisibilityScope::forRole($role));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: VisibilityScope}>
     */
    public static function roleScopeProvider(): iterable
    {
        yield 'admin -> all' => [Role::Admin->value, VisibilityScope::All];
        yield 'director -> all' => [Role::Director->value, VisibilityScope::All];
        yield 'lawyer -> all' => [Role::Lawyer->value, VisibilityScope::All];
        yield 'manager -> own' => [Role::Manager->value, VisibilityScope::Own];
        yield 'accountant -> own' => [Role::Accountant->value, VisibilityScope::Own];
        yield 'cfo -> own' => [Role::Cfo->value, VisibilityScope::Own];
        yield 'unknown role -> own (fail-closed)' => ['ghost', VisibilityScope::Own];
        yield 'no role -> own (fail-closed)' => [null, VisibilityScope::Own];
    }
}
