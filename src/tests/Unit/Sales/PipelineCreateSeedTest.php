<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Service-level invariants for Pipeline CRUD (Д-bis / Q1): system-stage autoseed,
 * settings filtering (Q3), and last-sales / has-deals delete guards.
 */
class PipelineCreateSeedTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PipelineService
    {
        return app(PipelineService::class);
    }

    public function test_create_autoseeds_three_system_stages(): void
    {
        $pipeline = $this->service()->create(['name' => 'Brand new']);

        $this->assertSame(PipelineKind::Sales, $pipeline->kind);

        $stages = PipelineStage::where('pipeline_id', $pipeline->id)
            ->orderBy('sort_order')->get();

        $this->assertSame(['new', 'won', 'lost'], $stages->pluck('code')->all());

        $won = $stages->firstWhere('code', 'won');
        $this->assertTrue($won->is_won);
        $this->assertTrue($won->won_gate);

        $lost = $stages->firstWhere('code', 'lost');
        $this->assertTrue($lost->is_lost);
        $this->assertTrue($lost->hidden_by_default);

        $new = $stages->firstWhere('code', 'new');
        $this->assertFalse($new->is_won);
        $this->assertFalse($new->is_lost);
        $this->assertFalse($new->hidden_by_default);
    }

    public function test_create_filters_unsafe_settings_keys(): void
    {
        $pipeline = $this->service()->create([
            'name' => 'Filtered',
            'settings' => [
                'auto_assign' => true,
                'duplicate_check_enabled' => true,
                'kanban' => ['compact' => true],
            ],
        ]);

        $this->assertArrayNotHasKey('auto_assign', $pipeline->settings);
        $this->assertArrayNotHasKey('duplicate_check_enabled', $pipeline->settings);
        $this->assertArrayHasKey('kanban', $pipeline->settings);
    }

    public function test_update_ignores_kind_and_filters_settings(): void
    {
        $pipeline = $this->service()->create(['name' => 'P']);

        $updated = $this->service()->update($pipeline, [
            'kind' => 'lifecycle',
            'settings' => ['auto_assign' => true, 'kanban' => ['x' => 1]],
        ]);

        $this->assertSame(PipelineKind::Sales, $updated->kind);
        $this->assertArrayNotHasKey('auto_assign', $updated->settings);
        $this->assertArrayHasKey('kanban', $updated->settings);
    }

    public function test_delete_last_sales_pipeline_throws_422(): void
    {
        $pipeline = $this->service()->create(['name' => 'Only one']);

        try {
            $this->service()->delete($pipeline);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
        }

        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
    }

    public function test_delete_secondary_pipeline_cascades_stages(): void
    {
        $first = $this->service()->create(['name' => 'First']);
        $second = $this->service()->create(['name' => 'Second']);

        $this->service()->delete($second);

        $this->assertDatabaseMissing('pipelines', ['id' => $second->id]);
        $this->assertDatabaseMissing('pipeline_stages', ['pipeline_id' => $second->id]);
        $this->assertDatabaseHas('pipelines', ['id' => $first->id]);
    }
}
