<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HD1: every Sales destroy endpoint returns 204 No Content (unified).
 */
class StageHardeningTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_lost_reason_destroy_returns_204(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $reason = LostReason::factory()->create();

        $this->deleteJson("/api/lost-reasons/{$reason->id}")->assertNoContent();
    }

    public function test_deal_destroy_returns_204(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/deals/{$deal->id}")->assertNoContent();
    }

    public function test_deal_product_destroy_returns_204(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'currency' => 'RUB',
        ]);
        $product = Product::factory()->create();
        $line = DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100_00,
            'amount' => 100_00,
            'currency' => 'RUB',
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/deals/{$deal->id}/products/{$line->id}")->assertNoContent();
    }

    public function test_deal_contact_destroy_returns_204(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $contact = Contact::factory()->create();
        $link = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/deals/{$deal->id}/contacts/{$link->id}")->assertNoContent();
    }

    public function test_stage_destroy_returns_204(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stage = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}")->assertNoContent();
    }
}
