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
 * CRM-5 regression fix: the 7 shared reference catalogs under /api/admin/*
 * (company-types, sources, countries, cities, contact-positions,
 * acquisition-channels, disconnect-reasons) split READ from WRITE.
 *
 *  - READ (index/show): auth:sanctum only. Every authenticated CRM role needs
 *    them for filter dropdowns, type/source/country labels, and the
 *    DisconnectDialog reason picker. A blanket can:admin-write read-gate 403'd
 *    these and blanked the shared directories store for manager/lawyer/etc.
 *  - WRITE (store/update/destroy): can:admin-write (admin/director). Gates stay
 *    at the route layer AND in each controller (defense in depth) — no inline
 *    role checks.
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
    public function test_manager_can_read_admin_directory(string $endpoint): void
    {
        // Reads are open to any authenticated CRM role — the catalogs feed
        // filter dropdowns, labels and the disconnect reason picker.
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson($endpoint)->assertOk();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_lawyer_can_read_admin_directory(string $endpoint): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Sanctum::actingAs($lawyer, ['*']);

        $this->getJson($endpoint)->assertOk();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_accountant_can_read_admin_directory(string $endpoint): void
    {
        $accountant = User::factory()->create(['role' => Role::Accountant]);
        Sanctum::actingAs($accountant, ['*']);

        $this->getJson($endpoint)->assertOk();
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

    #[DataProvider('directoryEndpointProvider')]
    public function test_manager_cannot_write_admin_directory(string $endpoint): void
    {
        // Writes stay admin/director-only via the can:admin-write route group.
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson($endpoint, ['name' => 'Forbidden'])->assertForbidden();
    }

    #[DataProvider('directoryEndpointProvider')]
    public function test_lawyer_cannot_write_admin_directory(string $endpoint): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Sanctum::actingAs($lawyer, ['*']);

        $this->postJson($endpoint, ['name' => 'Forbidden'])->assertForbidden();
    }

    public function test_unauthenticated_admin_directory_is_401(): void
    {
        $this->getJson('/api/admin/countries')->assertStatus(401);
    }
}
