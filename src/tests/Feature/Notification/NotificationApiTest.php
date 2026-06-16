<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_index_returns_grouped_buckets_digest_and_unread_count(): void
    {
        $user = $this->actingUser();

        // 2 actionable + unread → "needs attention"
        Notification::factory()->for($user)->actionable()->count(2)->create();
        // 1 actionable but already read → belongs to the feed, not actionable
        Notification::factory()->for($user)->actionable()->read()->create();
        // 3 plain unread feed items
        Notification::factory()->for($user)->count(3)->create();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'actionable')
            ->assertJsonPath('unread_count', 5)
            ->assertJsonPath('digest.unread_total', 5)
            ->assertJsonStructure([
                'actionable' => [['id', 'category', 'title', 'is_actionable', 'action_label', 'is_read', 'read_at']],
                'feed' => ['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']],
                'digest' => ['unread_total', 'by_category'],
                'unread_count',
            ]);
    }

    public function test_actionable_items_are_not_duplicated_in_feed(): void
    {
        $user = $this->actingUser();

        $actionable = Notification::factory()->for($user)->actionable()->create();
        Notification::factory()->for($user)->count(2)->create();

        $response = $this->getJson('/api/notifications')->assertOk();

        $feedIds = collect($response->json('feed.data'))->pluck('id')->all();

        $this->assertNotContains($actionable->id, $feedIds);
        $this->assertCount(2, $feedIds);
    }

    public function test_digest_groups_unread_by_category(): void
    {
        $user = $this->actingUser();

        Notification::factory()->for($user)->category(NotificationCategory::Task)->count(2)->create();
        Notification::factory()->for($user)->category(NotificationCategory::Approval)->create();
        // read approval should not be counted in the digest
        Notification::factory()->for($user)->category(NotificationCategory::Approval)->read()->create();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('digest.unread_total', 3)
            ->assertJsonPath('digest.by_category.task', 2)
            ->assertJsonPath('digest.by_category.approval', 1);
    }

    public function test_feed_is_paginated(): void
    {
        $user = $this->actingUser();

        Notification::factory()->for($user)->count(25)->create();

        $this->getJson('/api/notifications?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'feed.data')
            ->assertJsonPath('feed.meta.total', 25)
            ->assertJsonPath('feed.meta.per_page', 10)
            ->assertJsonPath('feed.meta.last_page', 3);
    }

    public function test_feed_second_page_returns_next_records_not_duplicates_of_first(): void
    {
        $user = $this->actingUser();

        // 25 plain feed items. Feed orders by id desc, so page 1 holds the 10
        // highest ids and page 2 the next 10 — with no overlap.
        Notification::factory()->for($user)->count(25)->create();

        $firstPage = $this->getJson('/api/notifications?per_page=10&feed_page=1')
            ->assertOk()
            ->assertJsonCount(10, 'feed.data')
            ->assertJsonPath('feed.meta.current_page', 1);

        $secondPage = $this->getJson('/api/notifications?per_page=10&feed_page=2')
            ->assertOk()
            ->assertJsonCount(10, 'feed.data')
            ->assertJsonPath('feed.meta.current_page', 2);

        $firstIds = collect($firstPage->json('feed.data'))->pluck('id')->all();
        $secondIds = collect($secondPage->json('feed.data'))->pluck('id')->all();

        // The core regression: ?feed_page=2 must advance the cursor (paginate()
        // defaults to ?page, so without pageName the second call would repeat
        // page 1). No id appears on both pages.
        $this->assertEmpty(
            array_intersect($firstIds, $secondIds),
            'feed_page=2 returned the same records as feed_page=1 — the page cursor was ignored.',
        );
        $this->assertCount(20, array_unique([...$firstIds, ...$secondIds]));
    }

    public function test_per_page_is_bounded(): void
    {
        $this->actingUser();

        $this->getJson('/api/notifications?per_page=500')
            ->assertStatus(422);
    }

    public function test_index_only_returns_callers_own_notifications(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        Notification::factory()->for($user)->count(2)->create();
        Notification::factory()->for($other)->count(5)->create();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'feed.data');
    }

    public function test_mark_one_read(): void
    {
        $user = $this->actingUser();
        $notification = Notification::factory()->for($user)->actionable()->create();

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, Notification::query()->forUser($user->id)->unread()->count());
    }

    public function test_mark_one_read_is_idempotent(): void
    {
        $user = $this->actingUser();
        $notification = Notification::factory()->for($user)->read()->create();
        $originalReadAt = $notification->read_at;

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        // read_at is not bumped on a re-mark
        $this->assertEquals(
            $originalReadAt->toIso8601String(),
            $notification->fresh()->read_at->toIso8601String(),
        );
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $this->actingUser();
        $other = User::factory()->create();
        $notification = Notification::factory()->for($other)->create();

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = $this->actingUser();
        Notification::factory()->for($user)->count(4)->create();
        Notification::factory()->for($user)->read()->create();

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 4)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, Notification::query()->forUser($user->id)->unread()->count());
    }

    public function test_mark_all_read_only_touches_callers_notifications(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();

        Notification::factory()->for($user)->count(2)->create();
        Notification::factory()->for($other)->count(3)->create();

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 2);

        $this->assertSame(3, Notification::query()->forUser($other->id)->unread()->count());
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
