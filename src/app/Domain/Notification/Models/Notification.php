<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use Database\Factories\Notification\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Notification — one in-app notification for one recipient (task #9). Source of
 * truth for the navigation notification flyout. All creation/dispatch logic
 * lives in NotificationService; the model is fillable + casts + the recipient
 * relation + read-state helpers only (ARCHITECTURE.md §2).
 *
 * No updated_at: a notification is immutable once created. Marking read flips
 * read_at, it never "edits" the notification body — so we keep created_at only.
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }

    protected $table = 'notifications';

    /** No updated_at column — see class doc. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'category',
        'title',
        'body',
        'is_actionable',
        'action_label',
        'deep_link',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'is_actionable' => 'boolean',
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Scope implicit route-model binding to the authenticated recipient.
     *
     * A notification is private to its receiver, so a `{notification}` bound to
     * someone else's row must look the same as one that does not exist: both
     * resolve to null → 404. Without this, a foreign-but-real id would bind and
     * the policy would 403, while a non-existent id would 404 — that 403/404
     * split is a minor ID oracle (a caller could tell which ids exist). Scoping
     * the binding to user_id collapses both cases to a uniform 404.
     *
     * @param  mixed  $value
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('user_id', $userId)
            ->first();
    }

    /** Recipient. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    // ---- Query scopes (read-state filters used by NotificationService) ----

    /** @param  Builder<Notification>  $query */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** @param  Builder<Notification>  $query */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** @param  Builder<Notification>  $query */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->where('is_actionable', true);
    }
}
