<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\LinkRedeemResult;
use App\Domain\Notification\Models\TelegramLinkToken;
use App\Domain\Notification\Services\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private TelegramLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('crm.telegram.bot_username', 'macro_test_bot');
        config()->set('crm.telegram.link_ttl_minutes', 10);
        $this->service = app(TelegramLinkService::class);
    }

    public function test_issue_creates_token_with_ttl_and_deeplink(): void
    {
        $user = User::factory()->create();

        $result = $this->service->issueFor($user);

        $this->assertSame(10, $result['expires_in_minutes']);
        $this->assertStringContainsString('https://t.me/macro_test_bot?start=link_', $result['deeplink']);

        $token = TelegramLinkToken::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($token->used_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addMinutes(9)));
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addMinutes(10)));
        // The token value must NOT leak into the deeplink path twice / be guessable short.
        $this->assertStringContainsString($token->token, $result['deeplink']);
    }

    public function test_redeem_valid_token_links_user(): void
    {
        $user = User::factory()->create(['telegram_user_id' => null]);
        $token = TelegramLinkToken::factory()->create(['user_id' => $user->id]);

        $result = $this->service->redeem($token->token, '555000111');

        $this->assertSame(LinkRedeemResult::Linked, $result);
        $this->assertSame('555000111', $user->fresh()->telegram_user_id);
        $this->assertNotNull($token->fresh()->used_at);
    }

    public function test_redeem_used_token_fails(): void
    {
        $user = User::factory()->create();
        $token = TelegramLinkToken::factory()->used()->create(['user_id' => $user->id]);

        $result = $this->service->redeem($token->token, '555000111');

        $this->assertSame(LinkRedeemResult::AlreadyUsed, $result);
        $this->assertNull($user->fresh()->telegram_user_id);
    }

    public function test_redeem_expired_token_fails(): void
    {
        $user = User::factory()->create();
        $token = TelegramLinkToken::factory()->expired()->create(['user_id' => $user->id]);

        $result = $this->service->redeem($token->token, '555000111');

        $this->assertSame(LinkRedeemResult::Expired, $result);
        $this->assertNull($user->fresh()->telegram_user_id);
    }

    public function test_redeem_unknown_token_fails(): void
    {
        $result = $this->service->redeem('does-not-exist', '555000111');

        $this->assertSame(LinkRedeemResult::Invalid, $result);
    }

    public function test_redeem_tg_already_linked_to_other_user_fails(): void
    {
        // Another user already owns this Telegram account.
        User::factory()->create(['telegram_user_id' => '555000111']);

        $user = User::factory()->create(['telegram_user_id' => null]);
        $token = TelegramLinkToken::factory()->create(['user_id' => $user->id]);

        $result = $this->service->redeem($token->token, '555000111');

        $this->assertSame(LinkRedeemResult::LinkedToOther, $result);
        $this->assertNull($user->fresh()->telegram_user_id);
        $this->assertNull($token->fresh()->used_at);
    }

    public function test_redeem_same_tg_already_on_same_user_succeeds(): void
    {
        // Re-linking the same account to the same user must not trip the guard.
        $user = User::factory()->create(['telegram_user_id' => '555000111']);
        $token = TelegramLinkToken::factory()->create(['user_id' => $user->id]);

        $result = $this->service->redeem($token->token, '555000111');

        $this->assertSame(LinkRedeemResult::Linked, $result);
    }

    public function test_unlink_clears_telegram_user_id(): void
    {
        $user = User::factory()->create(['telegram_user_id' => '555000111']);

        $this->service->unlink($user);

        $this->assertNull($user->fresh()->telegram_user_id);
    }
}
