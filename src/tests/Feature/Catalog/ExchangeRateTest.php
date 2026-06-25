<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Domain\Catalog\Jobs\UpdateExchangeRatesJob;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    // ---- index returns paginated response with meta ----

    public function test_index_returns_paginated_meta(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        // Three rows for the same pair on three distinct dates to avoid UNIQUE violation.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            ExchangeRate::factory()->create([
                'from_code' => 'USD',
                'to_code' => 'KZT',
                'date' => $date,
            ]);
        }
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/catalog/exchange-rates')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    // ---- upsert idempotency ----

    public function test_upsert_exchange_rate_idempotent(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $payload = [
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'rate' => '450.250000',
            'date' => '2026-06-12',
            'source' => 'manual',
        ];

        // First call: creates (201).
        $this->postJson('/api/catalog/exchange-rates', $payload)
            ->assertStatus(201);

        // Second call with same from/to/date: updates existing row (200).
        $payload['rate'] = '451.000000';
        $this->postJson('/api/catalog/exchange-rates', $payload)
            ->assertOk();

        // Exactly one row in DB.
        $this->assertDatabaseCount('catalog_exchange_rates', 1);
        $this->assertDatabaseHas('catalog_exchange_rates', ['rate' => '451.000000']);
    }

    // ---- convert endpoint ----

    public function test_convert_currency_endpoint(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'rate' => '450.000000',
            'date' => Carbon::today()->toDateString(),
        ]);
        Sanctum::actingAs($user, ['*']);

        // 100 USD in kopecks = 10000 kopecks; converted to KZT = 10000 * 450 = 4500000 kopecks.
        $this->getJson('/api/catalog/exchange-rates/convert?from=USD&to=KZT&amount=10000')
            ->assertOk()
            ->assertJsonPath('data.from_code', 'USD')
            ->assertJsonPath('data.to_code', 'KZT')
            ->assertJsonPath('data.to_amount', 4500000);
    }

    // ---- patch conflict → 409 ----

    public function test_duplicate_rate_on_patch_returns_409(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $rate1 = ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'date' => '2026-06-10',
            'rate' => '448.000000',
        ]);
        $rate2 = ExchangeRate::factory()->create([
            'from_code' => 'USD',
            'to_code' => 'KZT',
            'date' => '2026-06-11',
            'rate' => '449.000000',
        ]);
        Sanctum::actingAs($user, ['*']);

        // Changing rate2's date to match rate1's existing date → 409.
        $this->patchJson("/api/catalog/exchange-rates/{$rate2->id}", ['date' => '2026-06-10'])
            ->assertStatus(409);
    }

    // ---- write auth ----

    public function test_manager_cannot_create_rate(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/exchange-rates', [
            'from_code' => 'USD',
            'to_code' => 'RUB',
            'rate' => '90.0',
            'date' => '2026-06-12',
        ])->assertForbidden();
    }

    // ---- same-currency convert returns amount as-is with rate 1 ----

    public function test_convert_same_currency_returns_amount_unchanged(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        // No rows seeded — same-currency must NOT hit the DB.
        $this->getJson('/api/catalog/exchange-rates/convert?from=USD&to=USD&amount=50000')
            ->assertOk()
            ->assertJsonPath('data.from_code', 'USD')
            ->assertJsonPath('data.to_code', 'USD')
            ->assertJsonPath('data.from_amount', 50000)
            ->assertJsonPath('data.to_amount', 50000)
            ->assertJsonPath('data.rate', '1.000000');
    }

    public function test_convert_same_currency_rub_returns_amount_unchanged(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/catalog/exchange-rates/convert?from=RUB&to=RUB&amount=0')
            ->assertOk()
            ->assertJsonPath('data.to_amount', 0)
            ->assertJsonPath('data.rate', '1.000000');
    }

    // ---- missing rate → validation error on convert ----

    public function test_convert_missing_rate_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/catalog/exchange-rates/convert?from=USD&to=KZT&amount=10000')
            ->assertStatus(422);
    }

    // ---- on-demand refresh endpoint ----

    public function test_admin_can_trigger_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/exchange-rates/refresh')
            ->assertStatus(202)
            ->assertJsonPath('message', 'Exchange rate refresh queued.');

        Queue::assertPushed(UpdateExchangeRatesJob::class);
    }

    public function test_director_can_trigger_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/exchange-rates/refresh')
            ->assertStatus(202);

        Queue::assertPushed(UpdateExchangeRatesJob::class);
    }

    public function test_manager_cannot_trigger_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/catalog/exchange-rates/refresh')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
