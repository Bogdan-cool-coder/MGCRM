<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Notification\TelegramLinkTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TelegramLinkToken (S2.9) — one-shot deeplink token that binds a User to a
 * Telegram account via /start link_<token>.
 *
 * Valid iff used_at IS NULL AND expires_at > now(). After redemption used_at is
 * stamped (not deleted — audit). All validity logic lives in TelegramLinkService;
 * this model only declares fillable/casts/relation/scope (ARCHITECTURE.md §1).
 */
class TelegramLinkToken extends Model
{
    /** @use HasFactory<TelegramLinkTokenFactory> */
    use HasFactory;

    protected static function newFactory(): TelegramLinkTokenFactory
    {
        return TelegramLinkTokenFactory::new();
    }

    protected $table = 'telegram_link_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ---- Scopes ----

    /**
     * Tokens that are still redeemable: not used and not expired.
     *
     * @param  Builder<TelegramLinkToken>  $query
     * @return Builder<TelegramLinkToken>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
