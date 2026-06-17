<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Http\Requests\System\SystemResetRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for POST /api/system/reset (admin-only "Сброс настроек").
 *
 * IMPORTANT: these tests NEVER run the real reset. The underlying console kernel
 * `call()` is mocked so the controller's Artisan::call('app:reset-clean') does
 * not execute migrate:fresh against the (sqlite :memory:) test DB. We assert the
 * GUARDS (config flag, admin-only, confirmation phrase) and the response shape.
 */
class SystemResetTest extends TestCase
{
    use RefreshDatabase;

    private function enableReset(): void
    {
        config()->set('system.reset_enabled', true);
    }

    /**
     * Stub Artisan::call() so the controller does NOT actually run migrate:fresh
     * against the test DB (which is wrapped in a RefreshDatabase transaction —
     * migrate:fresh would fail to VACUUM and, worse, wipe state). The Artisan
     * facade is a partial mock: app:reset-clean is intercepted, everything else
     * falls through to the real kernel.
     */
    private function fakeResetCommand(): void
    {
        Artisan::partialMock()
            ->shouldReceive('call')
            ->with('app:reset-clean', \Mockery::any())
            ->andReturn(0);
    }

    private function validPayload(): array
    {
        return ['confirmation' => SystemResetRequest::CONFIRMATION_PHRASE];
    }

    // -------------------------------------------------------------------------
    // Auth / role
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->enableReset();

        $this->postJson('/api/system/reset', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_manager_is_forbidden(): void
    {
        $this->enableReset();

        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/system/reset', $this->validPayload())
            ->assertForbidden();
    }

    public function test_director_is_forbidden(): void
    {
        // Reset is strictly admin-only — director (who has admin-write) is denied.
        $this->enableReset();

        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/system/reset', $this->validPayload())
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Feature flag
    // -------------------------------------------------------------------------

    public function test_disabled_flag_forbids_even_admin(): void
    {
        config()->set('system.reset_enabled', false);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/system/reset', $this->validPayload())
            ->assertForbidden();
    }

    public function test_default_flag_is_false(): void
    {
        // The shipped default must be OFF (destructive op, opt-in per env).
        $this->assertFalse((bool) config('system.reset_enabled'));
    }

    // -------------------------------------------------------------------------
    // Confirmation phrase
    // -------------------------------------------------------------------------

    public function test_missing_confirmation_phrase_returns_422(): void
    {
        $this->enableReset();
        $this->fakeResetCommand();

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/system/reset', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_wrong_confirmation_phrase_returns_422(): void
    {
        $this->enableReset();
        $this->fakeResetCommand();

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/system/reset', ['confirmation' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    // -------------------------------------------------------------------------
    // Happy path (command mocked — DB is NOT wiped)
    // -------------------------------------------------------------------------

    public function test_admin_with_flag_and_phrase_triggers_reset_and_flags_relogin(): void
    {
        $this->enableReset();
        $this->fakeResetCommand();

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/system/reset', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.requires_relogin', true);
    }
}
