<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_current_user_with_full_token(): void
    {
        $user = User::factory()->create(['email' => 'me@mgcrm.test']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'me@mgcrm.test')
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_me_without_token_is_unauthenticated(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_rejects_temp_2fa_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['2fa:validate']);

        $this->getJson('/api/me')->assertStatus(403);
    }

    public function test_user_resource_never_exposes_totp_secret_or_backup_codes(): void
    {
        $user = User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111', 'bbbb2222'])
            ->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/me')->assertOk();

        $response->assertJsonMissingPath('data.totp_secret');
        $response->assertJsonMissingPath('data.backup_codes');
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonPath('data.totp_enabled', true);
    }
}
