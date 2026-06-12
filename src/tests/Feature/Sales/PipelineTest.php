<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_sales_pipelines_with_stages(): void
    {
        $this->seed(PipelineSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/pipelines?kind=sales')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Продажи')
            ->assertJsonCount(11, 'data.0.stages');
    }

    public function test_stages_ordered_with_system_stages_last(): void
    {
        $this->seed(PipelineSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $response = $this->getJson('/api/pipelines?kind=sales')->assertOk();

        $stages = $response->json('data.0.stages');
        $codes = array_column($stages, 'code');

        // System won/lost stages always sort to the bottom of the funnel list.
        $this->assertSame(
            ['new', 'qualify', 'schedule_meeting', 'meeting', 'cold', 'warm', 'hot', 'won', 'await_payment', 'paid', 'lost'],
            $codes,
        );
    }

    public function test_manager_cannot_create_lost_reason(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/lost-reasons', ['name' => 'Test reason'])
            ->assertForbidden();
    }

    public function test_admin_can_create_lost_reason(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson('/api/lost-reasons', ['name' => 'Конкурент'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Конкурент');
    }
}
