<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Support\MessageFormatter;
use App\Domain\Automation\Support\RecipientResolver;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Validation\ValidationException;

/**
 * create_task — create an Activity(kind=task) on the deal.
 *
 * Reuses Activity\ActivityService::create() (the only Activity write path) so
 * the task_types gate, visibility scoping and department denormalisation all
 * apply. The automation acts on behalf of its creator (or the deal owner) so the
 * service's creator-based visibility gate passes.
 *
 * config: { title, body, responsible: "owner"|"user_id:N", due_days: int }
 */
final class CreateTaskAction implements ActionHandler
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    public function kind(): ActionKind
    {
        return ActionKind::CreateTask;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $owner = $this->owner($target);
        $creator = $this->creator($automation, $owner);

        if ($creator === null) {
            return ActionResult::skipped('No actor available to create the task.');
        }

        $responsibleId = RecipientResolver::userId($config['responsible'] ?? 'owner', $target, $owner);
        $title = MessageFormatter::format(
            $config['title'] ?? "Auto-task: {$automation->name}",
            $target,
            $owner,
        );
        $body = isset($config['body'])
            ? MessageFormatter::format((string) $config['body'], $target, $owner)
            : null;
        $dueAt = $this->dueAt($config);

        try {
            $activity = $this->activities->create([
                'kind' => ActivityType::Task->value,
                'target_type' => ActivityTargetType::Deal->value,
                'target_id' => $target->id,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'responsible_id' => $responsibleId,
                'due_at' => $dueAt,
            ], $creator);
        } catch (ValidationException $e) {
            // The deal's current stage forbids the `task` kind in its task_types
            // whitelist (E1 gate). This is a deliberate stage config, not an engine
            // fault — record SKIPPED (not FAILED) so the run isn't flagged for retry
            // and the rest of the automation pass continues unbothered.
            if (array_key_exists('kind', $e->errors())) {
                return ActionResult::skipped(
                    $e->validator->errors()->first('kind') ?: 'Task kind is not allowed at this stage.',
                );
            }

            throw $e; // any other validation error stays a genuine failure
        }

        return ActionResult::success("Created task #{$activity->id}", [
            'activity_id' => $activity->id,
            'responsible_id' => $responsibleId,
            'due_at' => $dueAt,
        ]);
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $owner = $this->owner($target);
        $responsibleId = RecipientResolver::userId($config['responsible'] ?? 'owner', $target, $owner);

        return ActionPreview::will('Would create a task on this deal', [
            'task' => [
                'title' => MessageFormatter::format(
                    $config['title'] ?? "Auto-task: {$automation->name}",
                    $target,
                    $owner,
                ),
                'responsible_id' => $responsibleId,
                'due_at' => $this->dueAt($config),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dueAt(array $config): ?string
    {
        $days = isset($config['due_days']) ? (int) $config['due_days'] : 0;

        return $days > 0 ? now()->addDays($days)->toDateTimeString() : null;
    }

    private function creator(PipelineAutomation $automation, ?User $owner): ?User
    {
        if ($automation->created_by_user_id !== null) {
            $creator = User::find($automation->created_by_user_id);
            if ($creator !== null) {
                return $creator;
            }
        }

        return $owner;
    }

    private function owner(Deal $target): ?User
    {
        return $target->owner_user_id !== null ? User::find($target->owner_user_id) : null;
    }
}
