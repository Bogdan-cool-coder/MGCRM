<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Финансы-tab first-class payment fixation — POST /api/deals/{deal}/fix-payment.
 * Persists paid_at / paid_amount / payment_currency and appends ONE payment_fixed
 * entity-log row (the feed event the generic PATCH does not emit).
 */
class DealFixPaymentTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function dealFor(User $user): Deal
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, 'new');

        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stageId,
            'max_stage_id' => $stageId,
        ]);
    }

    public function test_fix_payment_persists_fields_and_logs_one_row(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/fix-payment", [
            'paid_at' => '2026-06-20',
            'paid_amount' => 1_500_000, // kopecks (15 000.00)
            'payment_currency' => 'USD',
        ])->assertOk()
            ->assertJsonPath('data.id', $deal->id);

        $fresh = $deal->fresh();
        $this->assertSame('2026-06-20', $fresh->paid_at?->toDateString());
        $this->assertSame(1_500_000, $fresh->paid_amount);
        $this->assertSame('USD', $fresh->payment_currency);

        // Exactly one payment_fixed row with the right meta.
        $logs = $fresh->newQuery()->getConnection()
            ->table('entity_logs')
            ->where('subject_type', 'deal')
            ->where('subject_id', $deal->id)
            ->where('action', 'payment_fixed')
            ->get();

        $this->assertCount(1, $logs);

        $meta = json_decode((string) $logs->first()->meta, true);
        $this->assertSame(1_500_000, $meta['amount']);
        $this->assertSame('USD', $meta['currency']);
        $this->assertSame('2026-06-20', $meta['paid_at']);

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'payment_fixed',
            'actor_id' => $user->id,
        ]);
    }

    public function test_fix_payment_forbidden_for_foreign_manager(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $stranger = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($owner);
        Sanctum::actingAs($stranger, ['*']);

        $this->postJson("/api/deals/{$deal->id}/fix-payment", [
            'paid_amount' => 1000,
            'payment_currency' => 'RUB',
        ])->assertForbidden();

        $this->assertNull($deal->fresh()->paid_amount);
        $this->assertDatabaseMissing('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'payment_fixed',
        ]);
    }

    public function test_fix_payment_rejects_invalid_currency(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/fix-payment", [
            'paid_amount' => 1000,
            'payment_currency' => 'GBP', // not in supported set
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('payment_currency');
    }

    public function test_fix_payment_rejects_negative_amount(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/deals/{$deal->id}/fix-payment", [
            'paid_amount' => -5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('paid_amount');
    }
}
