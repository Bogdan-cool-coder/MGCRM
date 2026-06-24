<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_active_users_with_option_shape(): void
    {
        $me = User::factory()->create(['full_name' => 'Alice Manager']);
        User::factory()->create(['full_name' => 'Bob Director', 'role' => Role::Director]);
        Sanctum::actingAs($me, ['*']);

        $response = $this->getJson('/api/users')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [
                ['id', 'full_name', 'email', 'avatar_path', 'department_id', 'role'],
            ],
        ]);
    }

    public function test_index_orders_by_full_name(): void
    {
        $me = User::factory()->create(['full_name' => 'Zoe Worker']);
        User::factory()->create(['full_name' => 'Anna Lead']);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Anna Lead')
            ->assertJsonPath('data.1.full_name', 'Zoe Worker');
    }

    public function test_index_excludes_inactive_users(): void
    {
        $me = User::factory()->create(['full_name' => 'Active One']);
        User::factory()->inactive()->create(['full_name' => 'Fired Two']);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Active One');
    }

    public function test_index_excludes_active_service_accounts(): void
    {
        $me = User::factory()->create(['full_name' => 'Human One']);
        // An active service/system principal (e.g. the AMO import user) must
        // never surface in owner/assignee dropdowns.
        User::factory()->create([
            'full_name' => 'Import Bot',
            'is_active' => true,
            'is_service' => true,
        ]);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Human One');
    }

    public function test_search_matches_name_case_insensitively(): void
    {
        $me = User::factory()->create(['full_name' => 'Charlie Brown']);
        User::factory()->create(['full_name' => 'Diana Prince']);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/users?search=DIAN')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Diana Prince');
    }

    public function test_search_matches_email(): void
    {
        $me = User::factory()->create(['email' => 'finder@mgcrm.test', 'full_name' => 'Finder One']);
        User::factory()->create(['email' => 'other@example.test', 'full_name' => 'Other Two']);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/users?search=finder@mgcrm')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'finder@mgcrm.test');
    }

    public function test_index_never_exposes_secrets(): void
    {
        $me = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111', 'bbbb2222'])
            ->create();
        Sanctum::actingAs($me, ['*']);

        $response = $this->getJson('/api/users')->assertOk();

        $response->assertJsonMissingPath('data.0.totp_secret');
        $response->assertJsonMissingPath('data.0.backup_codes');
        $response->assertJsonMissingPath('data.0.password');
        $response->assertJsonMissingPath('data.0.totp_enabled');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_index_rejects_temp_2fa_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['2fa:validate']);

        $this->getJson('/api/users')->assertStatus(403);
    }
}
