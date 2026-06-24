<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NEW-5 / CRM-5: the /api/admin/* directory group is gated to admin/director.
 *
 * Previously only write verbs were gated, so a manager could READ the shared
 * reference catalogs (company-types, sources, countries, cities,
 * contact-positions, acquisition-channels, disconnect-reasons) — including
 * sensitive BI (acquisition channels / disconnect reasons). The route-level
 * `can:admin-write` middleware now closes index/show for non-admin/director.
 *
 * Authorization stays Gate-based (admin-write) — no inline role checks.
 */
class AdminDirectoryGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function directoryEndpointProvider(): iterable
    {
        yield 'company-types' => ['/api/admin/company-types'];
        yield 'sources' => ['/api/admin/sources'];
        yield 'countries' => ['/api/admin/countries'];
        yield 'cities' => ['/api/admin/cities'];
        yield 'contact-positions' => ['/api/admin/contact-positions'];
        yield 'acquisition-channels' => ['/api/admin/acquisition-channels'];
        yield 'disconnect-reasons' => ['/api/admin/disconnect-reasons'];
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_manager_cannot_read_admin_directory(string $endpoint): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson($endpoint)->assertForbidden();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_lawyer_cannot_read_admin_directory(string $endpoint): void
    {
        // Lawyer has All record-visibility but is NOT an admin-write principal,
        // so the admin catalog group must still be 403 for them.
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Sanctum::actingAs($lawyer, ['*']);

        $this->getJson($endpoint)->assertForbidden();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_admin_can_read_admin_directory(string $endpoint): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson($endpoint)->assertOk();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_director_can_read_admin_directory(string $endpoint): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->getJson($endpoint)->assertOk();
    }

    public function test_unauthenticated_admin_directory_is_401(): void
    {
        $this->getJson('/api/admin/countries')->assertStatus(401);
    }
}
