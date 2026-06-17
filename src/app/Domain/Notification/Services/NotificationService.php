<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * NotificationService — the single entry point for IN-APP notifications (task
 * #9). Other domains call createForUser()/dispatch() to push a notification;
 * the API reads via buckets()/digest()/unreadCount()/feed() and mutates via
 * markRead()/markAllRead(). All business logic lives here (ARCHITECTURE.md §1);
 * controllers stay thin and never touch the model directly.
 *
 * Channel: IN-APP ONLY. Email/Telegram fan-out is intentionally out of scope
 * for this task (handled separately by the TG/email dispatch path).
 */
class NotificationService
{
    /**
     * Create + persist one in-app notification for a single recipient. This is
     * the method cross-domain listeners call.
     *
     * @param  array<string, mixed>|null  $data  structured payload for the flyout
     */
    public function createForUser(
        int $userId,
        NotificationCategory $category,
        string $title,
        ?string $body = null,
        bool $isActionable = false,
        ?string $actionLabel = null,
        ?string $deepLink = null,
        ?array $data = null,
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'category' => $category->value,
            'title' => $title,
            'body' => $body,
            'is_actionable' => $isActionable,
            'action_label' => $actionLabel,
            'deep_link' => $deepLink,
            'data' => $data,
        ]);
    }

    /**
     * Alias for createForUser() — the cross-domain "dispatch an in-app
     * notification" verb other services are documented to call.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function dispatch(
        int $userId,
        NotificationCategory $category,
        string $title,
        ?string $body = null,
        bool $isActionable = false,
        ?string $actionLabel = null,
        ?string $deepLink = null,
        ?array $data = null,
    ): Notification {
        return $this->createForUser(
            $userId,
            $category,
            $title,
            $body,
            $isActionable,
            $actionLabel,
            $deepLink,
            $data,
        );
    }

    /**
     * The grouped flyout payload for a user, in one shot:
     *   - actionable: unread "needs attention" items (is_actionable && unread)
     *   - feed: the paginated chronological ledger (everything else)
     *   - digest: counters (unread total + per-category breakdown)
     *   - unread_count: total unread
     *
     * The actionable bucket is intentionally NOT paginated — it is a small,
     * always-shown "to-do" list at the top of the flyout. The feed carries the
     * long tail and is paginated.
     *
     * @return array{
     *     actionable: Collection<int, Notification>,
     *     feed: LengthAwarePaginator<int, Notification>,
     *     digest: array{unread_total: int, by_category: array<string, int>},
     *     unread_count: int
     * }
     */
    public function grouped(User $user, int $perPage = 20): array
    {
        $actionable = $this->actionable($user);

        // Feed = everything that is NOT in the actionable bucket. We exclude the
        // unread-actionable ids so an item is never shown twice (top + feed).
        $feed = Notification::query()
            ->forUser($user->id)
            ->when(
                $actionable->isNotEmpty(),
                fn ($q) => $q->whereNotIn('id', $actionable->pluck('id')->all()),
            )
            ->orderByDesc('id')
            // The flyout sends ?feed_page=N (not the default ?page=N) so the
            // notification feed can be paged independently of any other
            // paginator on the page. Without this the page cursor is ignored
            // and load-more always returns page 1.
            ->paginate($perPage, pageName: 'feed_page');

        return [
            'actionable' => $actionable,
            'feed' => $feed,
            'digest' => $this->digest($user),
            'unread_count' => $this->unreadCount($user),
        ];
    }

    /**
     * "Needs attention" bucket: actionable AND unread, newest first.
     *
     * @return Collection<int, Notification>
     */
    public function actionable(User $user): Collection
    {
        return Notification::query()
            ->forUser($user->id)
            ->actionable()
            ->unread()
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Plain chronological list (used directly by feed and tests).
     *
     * @return LengthAwarePaginator<int, Notification>
     */
    public function list(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::query()
            ->forUser($user->id)
            ->orderByDesc('id')
            // Same feed_page cursor as grouped() — keep the page param name
            // consistent across both feed entry points.
            ->paginate($perPage, pageName: 'feed_page');
    }

    /**
     * Digest counters for the flyout badge / summary line.
     *
     * @return array{unread_total: int, by_category: array<string, int>}
     */
    public function digest(User $user): array
    {
        // Aggregate as a plain query (no model hydration) so `category` stays a
        // raw string and the enum cast never fires — `(string) $enum` is illegal.
        /** @var Collection<int, object{category: string, total: int}> $rows */
        $rows = Notification::query()
            ->forUser($user->id)
            ->unread()
            ->toBase()
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->get();

        $byCategory = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->total;
            $byCategory[(string) $row->category] = $count;
            $total += $count;
        }

        return [
            'unread_total' => $total,
            'by_category' => $byCategory,
        ];
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->forUser($user->id)
            ->unread()
            ->count();
    }

    /**
     * Mark one notification read. Idempotent — re-marking an already-read item
     * is a no-op (read_at is not bumped). Returns the fresh model.
     */
    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    /**
     * Mark all of a user's unread notifications read in one statement.
     * Returns the number of rows flipped.
     */
    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->forUser($user->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Mark a batch of notifications read by id, scoped to the caller.
     *
     * Cross-user safety: the user filter is part of the WHERE, so ids that
     * belong to another recipient are silently skipped — they are never read
     * nor leaked back (the return is only a count, not the affected ids).
     * Idempotent: the unread() filter means already-read ids are not re-stamped
     * and do not inflate the returned count.
     *
     * @param  list<int>  $ids
     * @return int number of rows actually flipped to read
     */
    public function markReadBatch(User $user, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Notification::query()
            ->forUser($user->id)
            ->unread()
            ->whereIn('id', $ids)
            ->update(['read_at' => now()]);
    }
}
