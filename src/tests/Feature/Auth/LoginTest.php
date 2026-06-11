<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_full_token(): void
    {
        $user = User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
            'role' => Role::Manager,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'manager@mgcrm.test')
            ->assertJsonPath('two_factor_required', false)
            ->assertJsonStructure(['data' => ['id', 'email', 'role'], 'token']);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create([
            'email' => 'manager@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'manager@mgcrm.test',
            'password' => 'wrong-pass',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_with_unknown_email_fails(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@mgcrm.test',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'gone@mgcrm.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'gone@mgcrm.test',
            'password' => 'secret-pass',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_with_2fa_user_returns_temp_token_not_full_token(): void
    {
        User::factory()
            ->withTwoFactor('JDDK4U6G3BJLHO6B', ['aaaa1111'])
            ->create([
                'email' => 'secure@mgcrm.test',
                'password' => Hash::make('secret-pass'),
            ]);

        $response = $this->postJson('/api/login', [
            'email' => 'secure@mgcrm.test',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['data', 'temp_token'])
            ->assertJsonMissingPath('token');
    }
}
