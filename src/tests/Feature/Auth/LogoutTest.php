<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'logout@mgcrm.test',
            'password' => 'secret-pass',
        ])->json('token');

        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_logout_without_token_is_unauthenticated(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }
}
