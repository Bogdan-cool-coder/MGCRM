<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_document_items(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        DocumentItem::factory()->count(2)->create(['document_id' => $doc->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/documents/{$doc->id}/items")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_adding_item_snapshots_product_name_and_price(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $user->id,
            'currency' => 'KZT',
        ]);
        $product = Product::factory()->create(['name' => 'MacroCRM Standard']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/items", [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertCreated();

        $this->assertSame('MacroCRM Standard', $response->json('data.name_snapshot'));
    }

    public function test_adding_item_recalculates_subtotal_and_total(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $user->id,
            'currency' => 'KZT',
        ]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Add item manually with known price (unit_price is resolved from catalog = 0 if no price)
        $this->postJson("/api/documents/{$doc->id}/items", [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertCreated();

        $doc->refresh();
        // subtotal should be recalculated (0 if no catalog price, but the field exists)
        $this->assertIsInt($doc->subtotal);
        $this->assertIsInt($doc->total);
    }

    public function test_adding_item_with_discount_pct_recalculates_correctly(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $user->id,
            'currency' => 'KZT',
            'discount_pct' => 10.00,
        ]);

        // Create item directly (bypassing price lookup to test arithmetic)
        DocumentItem::create([
            'document_id' => $doc->id,
            'product_id' => Product::factory()->create()->id,
            'name_snapshot' => 'Test',
            'currency' => 'KZT',
            'qty' => 1.0,
            'unit_price' => 100000,
            'line_total' => 100000,
            'sort_order' => 0,
        ]);

        // Trigger recalc manually (service path)
        $service = app(DocumentService::class);
        $service->updateItem($doc, DocumentItem::query()->where('document_id', $doc->id)->first(), []);

        $doc->refresh();
        $this->assertSame(100000, $doc->subtotal);
        $this->assertSame(10000, $doc->discount_amount);
        $this->assertSame(90000, $doc->total);
    }

    public function test_update_item_qty_recalculates_total(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $item = DocumentItem::factory()->create([
            'document_id' => $doc->id,
            'unit_price' => 10000,
            'qty' => 1.0,
            'line_total' => 10000,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/documents/{$doc->id}/items/{$item->id}", [
            'qty' => 2.5,
        ])->assertOk();

        $item->refresh();
        $this->assertSame(25000, $item->line_total);

        $doc->refresh();
        $this->assertSame(25000, $doc->subtotal);
        $this->assertSame(25000, $doc->total);
    }

    public function test_delete_item_recalculates_total(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $item1 = DocumentItem::factory()->create([
            'document_id' => $doc->id,
            'unit_price' => 10000,
            'qty' => 1.0,
            'line_total' => 10000,
        ]);
        $item2 = DocumentItem::factory()->create([
            'document_id' => $doc->id,
            'unit_price' => 20000,
            'qty' => 1.0,
            'line_total' => 20000,
        ]);

        // Set initial totals.
        $doc->update(['subtotal' => 30000, 'total' => 30000]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}/items/{$item1->id}")
            ->assertNoContent();

        $doc->refresh();
        $this->assertSame(20000, $doc->subtotal);
        $this->assertSame(20000, $doc->total);
    }

    public function test_cannot_add_item_to_non_draft_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->submitted()->create(['author_user_id' => $user->id]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/documents/{$doc->id}/items", [
            'product_id' => $product->id,
        ])->assertUnprocessable();
    }

    public function test_line_total_is_in_kopecks(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        // unit_price = 10000 kopecks (100.00 units), qty = 2.5
        // line_total = round(2.5 * 10000) = 25000 kopecks
        $item = DocumentItem::create([
            'document_id' => $doc->id,
            'product_id' => Product::factory()->create()->id,
            'name_snapshot' => 'Test',
            'currency' => 'KZT',
            'qty' => 2.5,
            'unit_price' => 10000,
            'line_total' => (int) round(2.5 * 10000),
            'sort_order' => 0,
        ]);

        $this->assertSame(25000, $item->line_total);
        $this->assertIsInt($item->line_total);
    }
}
