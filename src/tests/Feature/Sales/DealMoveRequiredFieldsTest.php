<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealMoveRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * Build a deal in `new`, with a target stage carrying the given required_fields.
     *
     * @param  array<string, list<string>>  $requiredFields
     * @return array{Deal, PipelineStage, User}
     */
    private function scenario(array $requiredFields, ?Company $company = null): array
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);

        $target = $pipeline->stages->firstWhere('code', 'qualify');
        $target->update(['required_fields' => $requiredFields]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->firstWhere('code', 'new')->id,
            'company_id' => ($company ?? Company::factory()->create())->id,
        ]);

        return [$deal, $target, $owner];
    }

    public function test_move_blocked_when_required_deal_field_missing(): void
    {
        // expected_close_date is null on a factory deal → move must be blocked.
        [$deal, $target, $owner] = $this->scenario(['deal' => ['expected_close_date']]);
        Sanctum::actingAs($owner, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('required_fields');

        $deal->refresh();
        $this->assertNotSame($target->id, $deal->stage_id);
    }

    public function test_move_blocked_when_required_company_field_missing(): void
    {
        $company = Company::factory()->create(['email' => null]);
        [$deal, $target, $owner] = $this->scenario(['company' => ['email']], $company);
        Sanctum::actingAs($owner, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('required_fields');
    }

    public function test_move_passes_when_required_fields_present(): void
    {
        $company = Company::factory()->create(['email' => 'ceo@acme.test']);
        [$deal, $target, $owner] = $this->scenario(
            ['deal' => ['expected_close_date'], 'company' => ['email']],
            $company,
        );
        $deal->update(['expected_close_date' => now()->addWeek()->toDateString()]);
        Sanctum::actingAs($owner, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $target->id);
    }

    public function test_move_ignores_required_fields_for_zero_amount(): void
    {
        // Move-level invariant: blank(0) === false, so a stored
        // required_fields:['amount'] never blocks the move (E, BUG-8). The editor
        // can no longer *configure* amount as required (it's off the whitelist —
        // see StageEditorTest), but assertRequiredFields stays defensive for any
        // legacy/stored value.
        [$deal, $target, $owner] = $this->scenario(['deal' => ['amount']]);
        $this->assertSame(0, $deal->amount);
        Sanctum::actingAs($owner, ['*']);

        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $target->id);
    }

    public function test_existing_deal_not_revalidated_on_stage_tighten(): void
    {
        // A deal already standing on a stage is not pushed out when its
        // required_fields are tightened — the gate only fires on entry (E6).
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $qualify = $pipeline->stages->firstWhere('code', 'qualify');

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $qualify->id, // already on qualify
        ]);

        // Tighten required_fields after the deal is already there.
        $qualify->update(['required_fields' => ['deal' => ['expected_close_date']]]);

        // A no-op move to the same stage is idempotent and must not 422.
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk();

        $deal->refresh();
        $this->assertSame($qualify->id, $deal->stage_id);
    }
}
