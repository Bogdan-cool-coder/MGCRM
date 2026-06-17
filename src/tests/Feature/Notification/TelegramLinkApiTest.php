<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Models\TelegramLinkToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature coverage for the Telegram link endpoints, wired into the real
 * routes/api.php me-group:
 *   POST   /api/me/telegram-link  → issue a deeplink (TelegramLinkResource)
 *   DELETE /api/me/telegram       → unlink (clears telegram_user_id)
 */
class TelegramLinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('crm.telegram.bot_username', 'macro_test_bot');
        config()->set('crm.telegram.link_ttl_minutes', 10);
    }

    public function test_issue_link_requires_auth(): void
    {
        $this->postJson('/api/me/telegram-link')->assertUnauthorized();
    }

    public function test_issue_link_returns_deeplink_and_persists_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/me/telegram-link');

        $response->assertOk()
            ->assertJsonStructure(['deeplink', 'expires_in_minutes'])
            ->assertJsonPath('expires_in_minutes', 10);

        $this->assertStringContainsString(
            'https://t.me/macro_test_bot?start=link_',
            $response->json('deeplink'),
        );
        $this->assertDatabaseHas('telegram_link_tokens', ['user_id' => $user->id, 'used_at' => null]);
    }

    public function test_unlink_clears_telegram_user_id(): void
    {
        $user = User::factory()->create(['telegram_user_id' => '555000111']);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson('/api/me/telegram')
            ->assertOk()
            ->assertJsonPath('telegram_user_id', null);

        $this->assertNull($user->fresh()->telegram_user_id);
    }

    public function test_issue_token_is_hidden_in_model_serialization(): void
    {
        $token = TelegramLinkToken::factory()->create();

        // The raw token must never leak through default model serialization.
        $this->assertArrayNotHasKey('token', $token->toArray());
    }
}
