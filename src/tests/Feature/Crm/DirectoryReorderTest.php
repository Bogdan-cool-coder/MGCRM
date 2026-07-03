<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\AcquisitionChannel;
use App\Domain\Crm\Models\Country;
use App\Domain\Crm\Models\DisconnectReason;
use App\Domain\Crm\Models\Tag;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drag-and-drop reorder for the flat sort_order admin directories
 * (settings-gaps ОВ-3, gap 2). All five endpoints share one implementation
 * (App\Support\SortOrderReorderer via Shared\ReorderDirectoryRequest), so this
 * suite covers the shared contract once per directory plus the cross-cutting
 * behaviours (array-order-wins renumber, admin gate, unknown-id 422 rollback).
 */
class DirectoryReorderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    // =========================================================================
    // Countries
    // =========================================================================

    public function test_reorder_countries_renumbers_to_array_order(): void
    {
        // Client-sent sort_order is irrelevant — array order is authoritative.
        $a = Country::create(['code' => 'aa', 'name' => 'AA', 'sort_order' => 5]);
        $b = Country::create(['code' => 'bb', 'name' => 'BB', 'sort_order' => 6]);
        $c = Country::create(['code' => 'cc', 'name' => 'CC', 'sort_order' => 7]);
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/countries/reorder', [
            'items' => [
                ['id' => $c->id],
                ['id' => $a->id],
                ['id' => $b->id],
            ],
        ])->assertOk()->assertJsonStructure(['message']);

        $this->assertSame(1, $c->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
    }

    public function test_reorder_countries_rejects_unknown_id_and_rolls_back(): void
    {
        $a = Country::create(['code' => 'aa', 'name' => 'AA', 'sort_order' => 5]);
        $this->actingAsAdmin();

        // First valid id would become sort_order=1; the unknown id then aborts
        // the whole transaction, so $a must retain its original sort_order.
        $this->patchJson('/api/admin/countries/reorder', [
            'items' => [
                ['id' => $a->id],
                ['id' => 999999],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('items');

        $this->assertSame(5, $a->fresh()->sort_order);
    }

    public function test_manager_cannot_reorder_countries(): void
    {
        $a = Country::create(['code' => 'aa', 'name' => 'AA', 'sort_order' => 5]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->patchJson('/api/admin/countries/reorder', [
            'items' => [['id' => $a->id]],
        ])->assertForbidden();

        $this->assertSame(5, $a->fresh()->sort_order);
    }

    public function test_reorder_requires_non_empty_items(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/countries/reorder', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('items');
    }

    // =========================================================================
    // Tags
    // =========================================================================

    public function test_reorder_tags_renumbers_to_array_order(): void
    {
        $a = Tag::create(['name' => 'Alpha', 'sort_order' => 10]);
        $b = Tag::create(['name' => 'Beta', 'sort_order' => 20]);
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/tags/reorder', [
            'items' => [['id' => $b->id], ['id' => $a->id]],
        ])->assertOk();

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    // =========================================================================
    // Acquisition channels
    // =========================================================================

    public function test_reorder_acquisition_channels_renumbers_to_array_order(): void
    {
        $a = AcquisitionChannel::create(['name' => 'Ads', 'sort_order' => 3]);
        $b = AcquisitionChannel::create(['name' => 'Referral', 'sort_order' => 1]);
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/acquisition-channels/reorder', [
            'items' => [['id' => $a->id], ['id' => $b->id]],
        ])->assertOk();

        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    // =========================================================================
    // Disconnect reasons
    // =========================================================================

    public function test_reorder_disconnect_reasons_renumbers_to_array_order(): void
    {
        $a = DisconnectReason::create(['name' => 'Price', 'sort_order' => 8]);
        $b = DisconnectReason::create(['name' => 'Service', 'sort_order' => 9]);
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/disconnect-reasons/reorder', [
            'items' => [['id' => $b->id], ['id' => $a->id]],
        ])->assertOk();

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    // =========================================================================
    // Lost reasons (Sales — Policy-gated create, not the admin-write Gate)
    // =========================================================================

    public function test_reorder_lost_reasons_renumbers_to_array_order(): void
    {
        $a = LostReason::create(['name' => 'Budget', 'sort_order' => 2]);
        $b = LostReason::create(['name' => 'Timing', 'sort_order' => 1]);
        $this->actingAsAdmin();

        $this->patchJson('/api/lost-reasons/reorder', [
            'items' => [['id' => $a->id], ['id' => $b->id]],
        ])->assertOk();

        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_manager_cannot_reorder_lost_reasons(): void
    {
        $a = LostReason::create(['name' => 'Budget', 'sort_order' => 2]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->patchJson('/api/lost-reasons/reorder', [
            'items' => [['id' => $a->id]],
        ])->assertForbidden();

        $this->assertSame(2, $a->fresh()->sort_order);
    }

    public function test_lost_reasons_reorder_not_bound_as_id_param(): void
    {
        // Route-ordering guard: /lost-reasons/reorder must not resolve as
        // /lost-reasons/{lostReason} with lostReason='reorder' (would 404 on
        // model binding, or worse, hit update).
        $a = LostReason::create(['name' => 'Budget', 'sort_order' => 2]);
        $this->actingAsAdmin();

        $this->patchJson('/api/lost-reasons/reorder', [
            'items' => [['id' => $a->id]],
        ])->assertOk();
    }
}
