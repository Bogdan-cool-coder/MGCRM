<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Self-service password change — POST /api/me/password (task #3).
 *
 * The user proves ownership with their current password (no email round-trip):
 * a correct current password rotates the credential (re-login with the new one
 * works), a wrong current password or a too-short new password both 422.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_changes_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $original = $user->password;
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();

        // The stored hash changed and the new plaintext verifies against it
        // (i.e. a re-login with the new password would succeed).
        $fresh = $user->fresh();
        $this->assertNotSame($original, $fresh->password);
        $this->assertTrue(Hash::check('brand-new-password-456', $fresh->password));
        $this->assertFalse(Hash::check('old-password-123', $fresh->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-current')]);
        $original = $user->password;
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/me/password', [
            'current_password' => 'wrong-current',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_short_new_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_unconfirmed_new_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'does-not-match',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_change_requires_authentication(): void
    {
        $this->postJson('/api/me/password', [
            'current_password' => 'whatever',
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertStatus(401);
    }
}
