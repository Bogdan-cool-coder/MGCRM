<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Service-level unit tests for the task_types gate (E1) on activity creation.
 */
class TaskTypesGateTest extends TestCase
{
    use RefreshDatabase;

    private function pipeline(): Pipeline
    {
        $this->seed(PipelineSeeder::class);

        return Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
    }

    private function dealInStage(Pipeline $pipeline, string $code, User $owner): Deal
    {
        $stage = $pipeline->stages->firstWhere('code', $code);

        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
    }

    public function test_kind_allowed_when_whitelist_empty(): void
    {
        $pipeline = $this->pipeline();
        $owner = User::factory()->create(['role' => Role::Director]);
        $deal = $this->dealInStage($pipeline, 'new', $owner);

        $service = app(ActivityService::class);

        $activity = $service->create([
            'kind' => ActivityType::Meeting->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'OK',
        ], $owner);

        $this->assertInstanceOf(Activity::class, $activity);
    }

    public function test_kind_blocked_when_not_in_whitelist(): void
    {
        $pipeline = $this->pipeline();
        $owner = User::factory()->create(['role' => Role::Director]);
        $deal = $this->dealInStage($pipeline, 'new', $owner);
        PipelineStage::whereKey($deal->stage_id)->update(['task_types' => ['call']]);

        $service = app(ActivityService::class);

        $this->expectException(ValidationException::class);

        $service->create([
            'kind' => ActivityType::Meeting->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Blocked',
        ], $owner);
    }

    public function test_gate_not_applied_to_company_target(): void
    {
        $this->pipeline();
        $owner = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => $owner->department_id,
        ]);

        $service = app(ActivityService::class);

        $activity = $service->create([
            'kind' => ActivityType::Meeting->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Company activity',
        ], $owner);

        $this->assertSame('company', $activity->target_type);
    }
}
