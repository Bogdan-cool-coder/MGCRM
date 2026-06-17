<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Seeders\PipelineSeeder;

/**
 * Shared helpers for Activity feature tests: seed the locked sales pipeline,
 * spin up scoped deals/companies and managers so visibility flows have real
 * targets to run on. Mirrors SalesTestHelpers.
 */
trait ActivityTestHelpers
{
    protected function seedSalesPipeline(): Pipeline
    {
        $this->seed(PipelineSeeder::class);

        return Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
    }

    protected function stage(Pipeline $pipeline, string $code): PipelineStage
    {
        return $pipeline->stages->firstWhere('code', $code);
    }

    protected function manager(?int $departmentId = null): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $departmentId,
        ]);
    }

    protected function director(): User
    {
        return User::factory()->create(['role' => Role::Director]);
    }

    /**
     * A deal owned by $owner placed in $code stage of $pipeline.
     */
    protected function dealFor(User $owner, Pipeline $pipeline, string $code = 'new'): Deal
    {
        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, $code)->id,
        ]);
    }

    protected function companyFor(User $owner): Company
    {
        return Company::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => $owner->department_id,
        ]);
    }
}
