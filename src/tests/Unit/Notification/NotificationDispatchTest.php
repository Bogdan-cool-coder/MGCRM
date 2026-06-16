<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityAssigned;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Listeners\NotifyActivityAssigneeListener;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function listener(): NotifyActivityAssigneeListener
    {
        return new NotifyActivityAssigneeListener(app(NotificationService::class));
    }

    public function test_assigning_a_task_creates_an_actionable_notification_for_the_responsible(): void
    {
        $creator = User::factory()->create();
        $responsible = User::factory()->create();

        $activity = Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'title' => 'Позвонить клиенту',
            'created_by_id' => $creator->id,
            'responsible_id' => $responsible->id,
            'target_type' => 'deal',
            'target_id' => 42,
        ]);

        $this->listener()->handle(new ActivityAssigned($activity, null));

        $notification = Notification::query()->where('user_id', $responsible->id)->sole();

        $this->assertSame(NotificationCategory::Task, $notification->category);
        $this->assertTrue($notification->is_actionable);
        $this->assertSame('Открыть задачу', $notification->action_label);
        $this->assertNull($notification->read_at);
        $this->assertSame('Назначена задача', $notification->title);
        $this->assertSame('Позвонить клиенту', $notification->body);
        $this->assertSame('/deals/42', $notification->deep_link);
        $this->assertSame(42, $notification->data['target_id']);
        $this->assertSame($activity->id, $notification->data['activity_id']);
    }

    public function test_self_assigned_task_does_not_notify(): void
    {
        $user = User::factory()->create();

        $activity = Activity::factory()->create([
            'kind' => ActivityType::Task->value,
            'created_by_id' => $user->id,
            'responsible_id' => $user->id,
        ]);

        $this->listener()->handle(new ActivityAssigned($activity, null));

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_note_assignment_does_not_notify(): void
    {
        $creator = User::factory()->create();
        $responsible = User::factory()->create();

        $activity = Activity::factory()->create([
            'kind' => ActivityType::Note->value,
            'created_by_id' => $creator->id,
            'responsible_id' => $responsible->id,
        ]);

        $this->listener()->handle(new ActivityAssigned($activity, null));

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_service_create_for_user_persists_all_fields(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);

        $notification = $service->createForUser(
            userId: $user->id,
            category: NotificationCategory::Approval,
            title: 'Запрошено согласование',
            body: 'Договор №7',
            isActionable: true,
            actionLabel: 'Согласовать',
            deepLink: '/documents/7',
            data: ['document_id' => 7],
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'category' => NotificationCategory::Approval->value,
            'title' => 'Запрошено согласование',
            'is_actionable' => true,
            'action_label' => 'Согласовать',
            'deep_link' => '/documents/7',
        ]);
        $this->assertSame(7, $notification->data['document_id']);
    }

    public function test_unread_count_and_digest_aggregate_correctly(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);

        Notification::factory()->for($user)->category(NotificationCategory::Task)->count(2)->create();
        Notification::factory()->for($user)->category(NotificationCategory::Deal)->create();
        Notification::factory()->for($user)->category(NotificationCategory::Deal)->read()->create();

        $this->assertSame(3, $service->unreadCount($user));

        $digest = $service->digest($user);
        $this->assertSame(3, $digest['unread_total']);
        $this->assertSame(2, $digest['by_category']['task']);
        $this->assertSame(1, $digest['by_category']['deal']);
    }
}
