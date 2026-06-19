<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use Carbon\CarbonImmutable;
use Database\Seeders\PipelineSeeder;

/**
 * Shared fixtures for the SalesPulse Slice 1 unit suite: seed the locked
 * "Продажи" funnel, build managers/deals/activities with controlled stage moves,
 * and assemble DaySnapshot/PulseTaskRow rows by hand. Kept DB-light: only the
 * funnel + the rows a given test needs.
 */
trait SalesPulseTestSupport
{
    private Pipeline $pipeline;

    /** @var array<string, PipelineStage> code => stage */
    private array $stages = [];

    private function seedFunnel(): void
    {
        $this->seed(PipelineSeeder::class);

        $this->pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();

        $this->stages = PipelineStage::where('pipeline_id', $this->pipeline->id)
            ->get()
            ->keyBy('code')
            ->all();
    }

    private function stage(string $code): PipelineStage
    {
        return $this->stages[$code];
    }

    private function makeManager(): User
    {
        return User::factory()->create();
    }

    private function makeDeal(string $stageCode, ?User $owner = null): Deal
    {
        $owner ??= $this->makeManager();

        return Deal::factory()
            ->inStage($this->stage($stageCode))
            ->create([
                'owner_user_id' => $owner->id,
                'company_id' => Company::factory()->create()->id,
            ]);
    }

    /**
     * Create a deal-bound activity for a manager. Defaults to an open task due
     * inside the day window.
     */
    private function makeActivity(
        User $manager,
        Deal $deal,
        ActivityType $kind = ActivityType::Task,
        ?CarbonImmutable $dueAt = null,
        ?CarbonImmutable $completedAt = null,
        bool $done = false,
        ?CarbonImmutable $createdAt = null,
    ): Activity {
        $attrs = [
            'responsible_id' => $manager->id,
            'kind' => $kind->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'due_at' => $dueAt,
            'completed_at' => $completedAt,
            'status' => $done ? ActivityStatus::Done->value : ActivityStatus::New->value,
            'completed_by_id' => $done ? $manager->id : null,
        ];

        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
            $attrs['updated_at'] = $createdAt;
        }

        return Activity::factory()->create($attrs);
    }

    /**
     * Build a PulseTaskRow with sensible defaults for snapshot/metric assembly.
     */
    private function row(
        int $taskId,
        int $dealId,
        ?int $stageId,
        bool $completed = false,
        ?string $updatedAt = null,
        ?string $dueAt = null,
        string $kind = 'task',
    ): PulseTaskRow {
        return new PulseTaskRow(
            taskId: $taskId,
            text: "task {$taskId}",
            kind: $kind,
            typeName: $kind,
            isCompleted: $completed,
            dueAt: $dueAt,
            updatedAt: $updatedAt,
            responsibleId: 1,
            resultText: null,
            dealId: $dealId,
            dealTitle: "deal {$dealId}",
            dealStageId: $stageId,
            dealStageName: 'stage',
            dealOwnerId: 1,
            dealUpdatedBy: 1,
            dealPipelineId: null,
        );
    }

    /**
     * Assemble a DaySnapshot from rows; leads_by_id is derived from the rows'
     * deal_id/stage_id (mirrors collect_day, WITHOUT status_name).
     *
     * @param  list<PulseTaskRow>  $plan
     * @param  list<PulseTaskRow>  $fact
     */
    private function snapshot(array $plan, array $fact = [], int $managerId = 1, string $onDate = '2026-06-19'): DaySnapshot
    {
        $leadsById = [];
        foreach ($plan as $r) {
            if ($r->dealId !== null && ! isset($leadsById[$r->dealId])) {
                $leadsById[$r->dealId] = [
                    'name' => $r->dealTitle,
                    'status_id' => $r->dealStageId,
                    'responsible_user_id' => $r->dealOwnerId,
                    'updated_by' => $r->dealUpdatedBy,
                ];
            }
        }

        return new DaySnapshot(
            managerId: $managerId,
            managerName: 'Manager',
            onDate: $onDate,
            plan: $plan,
            fact: $fact,
            leadsById: $leadsById,
        );
    }
}
