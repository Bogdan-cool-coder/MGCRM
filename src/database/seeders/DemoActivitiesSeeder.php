<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING demo activities so timelines and the "My tasks" page are alive
 * on a fresh dev DB. Idempotent: keyed by (title, target_type, target_id);
 * re-running does not duplicate. Depends on DemoDealsSeeder (deals) and
 * AdminSeeder (owner). Tests do NOT run this seeder.
 */
class DemoActivitiesSeeder extends Seeder
{
    /**
     * Per-deal demo activities: [kind, title, due offset days, status].
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string}>
     */
    private const PER_DEAL = [
        [ActivityType::Call->value, 'Первичный звонок', 1, ActivityStatus::New->value],
        [ActivityType::Meeting->value, 'Презентация продукта', 3, ActivityStatus::New->value],
        [ActivityType::Task->value, 'Подготовить КП', -1, ActivityStatus::InProgress->value],
        [ActivityType::Note->value, 'Клиент просил перезвонить после 15:00', 0, ActivityStatus::New->value],
    ];

    public function run(): void
    {
        $owner = User::where('role', Role::Admin->value)->orderBy('id')->first();
        if ($owner === null) {
            return;
        }

        $deals = Deal::query()->orderBy('id')->limit(5)->get();

        foreach ($deals as $deal) {
            foreach (self::PER_DEAL as [$kind, $title, $dueOffset, $status]) {
                $isNote = $kind === ActivityType::Note->value;
                $isDone = $status === ActivityStatus::Done->value;

                Activity::firstOrCreate(
                    [
                        'title' => $title,
                        'target_type' => ActivityTargetType::Deal->value,
                        'target_id' => $deal->id,
                    ],
                    [
                        'kind' => $kind,
                        'body' => null,
                        'due_at' => $isNote ? null : now()->addDays($dueOffset),
                        'completed_at' => $isDone ? now() : null,
                        'completed_by_id' => $isDone ? $owner->id : null,
                        'responsible_id' => $owner->id,
                        'created_by_id' => $owner->id,
                        'priority' => ActivityPriority::Normal->value,
                        'status' => $status,
                        'is_closed' => false,
                        'progress_pct' => $isDone ? 100 : 0,
                        'department_id' => $deal->department_id,
                    ],
                );
            }
        }
    }
}
