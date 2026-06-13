<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_link_tokens (S2.9) — one-shot deeplink token binding a User to a
 * Telegram account via /start link_<token>.
 *
 * Validity rule: used_at IS NULL AND expires_at > now(). After redemption we set
 * used_at = now() instead of deleting (audit). TTL is config('crm.telegram.link_ttl_minutes').
 *
 * No change to the `users` table — the bound chat id is stored in the existing
 * users.telegram_user_id column (Telegram private chat_id == user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // token_urlsafe(24)-equivalent (~32 chars); generous width to 64.
            $table->string('token', 64);
            $table->timestamp('expires_at');
            // NULL = still redeemable.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // One-shot lookup key.
            $table->unique('token');
            // "this user's tokens" + cleanup of expired tokens.
            $table->index('user_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
