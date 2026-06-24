<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyClientStatusLog;
use App\Domain\Crm\Models\DisconnectReason;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\DisconnectReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for N5 — client lifecycle status:
 *   - DisconnectReason admin directory CRUD + gate
 *   - CompanyService::markAsUniqueClient (idempotency)
 *   - CompanyService::disconnect (fields + log)
 *   - CompanyService::reconnect (status + log + field cleanup)
 *   - DisconnectReasonSeeder idempotence
 *   - GET /companies/{company}/status-log endpoint
 *   - POST /companies/{company}/disconnect endpoint
 *   - POST /companies/{company}/reconnect endpoint
 */
class ClientStatusTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function manager(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function director(): User
    {
        return User::factory()->create(['role' => Role::Director]);
    }

    private function reason(string $name = 'Сменил поставщика'): DisconnectReason
    {
        return DisconnectReason::create(['name' => $name, 'sort_order' => 1]);
    }

    private function company(?User $owner = null): Company
    {
        $owner ??= $this->manager();

        return Company::factory()->create(['owner_user_id' => $owner->id]);
    }

    // =========================================================================
    // DisconnectReason directory — admin CRUD
    // =========================================================================

    public function test_manager_cannot_list_disconnect_reasons(): void
    {
        // NEW-5: disconnect reasons are sensitive BI — the /api/admin/* group is
        // admin/director only, so a manager must get 403 on read.
        DisconnectReason::create(['name' => 'Причина 1', 'sort_order' => 1]);
        $user = $this->manager();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/disconnect-reasons')->assertForbidden();
    }

    public function test_admin_can_list_disconnect_reasons(): void
    {
        DisconnectReason::create(['name' => 'Причина 1', 'sort_order' => 1]);
        $user = $this->admin();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/disconnect-reasons')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_active_only_filter_on_disconnect_reasons(): void
    {
        DisconnectReason::create(['name' => 'Активная', 'sort_order' => 1, 'is_active' => true]);
        DisconnectReason::create(['name' => 'Неактивная', 'sort_order' => 2, 'is_active' => false]);

        // Reads on /api/admin/* require admin/director (NEW-5).
        $user = $this->admin();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/disconnect-reasons?active_only=1')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Активная', $names);
        $this->assertNotContains('Неактивная', $names);
    }

    public function test_manager_cannot_create_disconnect_reason(): void
    {
        Sanctum::actingAs($this->manager(), ['*']);

        $this->postJson('/api/admin/disconnect-reasons', ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_admin_can_create_disconnect_reason(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/admin/disconnect-reasons', [
            'name' => 'Сменил поставщика',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Сменил поставщика');

        $this->assertDatabaseHas('disconnect_reasons', ['name' => 'Сменил поставщика']);
    }

    public function test_director_can_update_disconnect_reason(): void
    {
        $reason = $this->reason('Старое');
        Sanctum::actingAs($this->director(), ['*']);

        $this->putJson("/api/admin/disconnect-reasons/{$reason->id}", [
            'name' => 'Новое',
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Новое');
    }

    public function test_admin_can_delete_disconnect_reason(): void
    {
        $reason = $this->reason('Удаляемая');
        Sanctum::actingAs($this->admin(), ['*']);

        $this->deleteJson("/api/admin/disconnect-reasons/{$reason->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('disconnect_reasons', ['id' => $reason->id]);
    }

    public function test_manager_cannot_delete_disconnect_reason(): void
    {
        $reason = $this->reason('Нельзя удалить');
        Sanctum::actingAs($this->manager(), ['*']);

        $this->deleteJson("/api/admin/disconnect-reasons/{$reason->id}")
            ->assertForbidden();
    }

    // =========================================================================
    // DisconnectReasonSeeder idempotence
    // =========================================================================

    public function test_disconnect_reason_seeder_is_idempotent(): void
    {
        $seeder = new DisconnectReasonSeeder;

        $seeder->run();
        $countFirst = DisconnectReason::count();

        $seeder->run();
        $countSecond = DisconnectReason::count();

        $this->assertSame($countFirst, $countSecond);
        $this->assertGreaterThan(0, $countFirst);
    }

    public function test_seeder_creates_expected_reasons(): void
    {
        (new DisconnectReasonSeeder)->run();

        $this->assertDatabaseHas('disconnect_reasons', ['name' => 'Сменил поставщика']);
        $this->assertDatabaseHas('disconnect_reasons', ['name' => 'Закрытие/банкротство']);
        $this->assertDatabaseHas('disconnect_reasons', ['name' => 'Другое']);
    }

    // =========================================================================
    // CompanyService::markAsUniqueClient
    // =========================================================================

    public function test_mark_as_unique_client_sets_status_and_date(): void
    {
        $company = $this->company();
        $signedAt = Carbon::parse('2025-03-15');

        app(CompanyService::class)->markAsUniqueClient($company, $signedAt, null);

        $company->refresh();

        $this->assertEquals(ClientStatus::Active, $company->client_status);
        $this->assertEquals('2025-03-15', $company->unique_client_since->toDateString());
    }

    public function test_mark_as_unique_client_writes_status_log(): void
    {
        $company = $this->company();
        $signedAt = Carbon::parse('2025-03-15');

        app(CompanyService::class)->markAsUniqueClient($company, $signedAt, null);

        $log = CompanyClientStatusLog::where('company_id', $company->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(ClientStatus::Prospect->value, $log->old_status->value);
        $this->assertEquals(ClientStatus::Active->value, $log->new_status->value);
        $this->assertArrayHasKey('signed_at', $log->meta ?? []);
    }

    public function test_mark_as_unique_client_is_idempotent(): void
    {
        $company = $this->company();
        $signedAt = Carbon::parse('2025-03-15');
        $service = app(CompanyService::class);

        $service->markAsUniqueClient($company, $signedAt, null);
        $company->refresh();
        $originalDate = $company->unique_client_since->toDateString();

        // Call again with a different date — must be a no-op
        $service->markAsUniqueClient($company, Carbon::parse('2026-01-01'), null);
        $company->refresh();

        $this->assertEquals($originalDate, $company->unique_client_since->toDateString());

        // Only one log entry must exist
        $this->assertSame(1, CompanyClientStatusLog::where('company_id', $company->id)->count());
    }

    public function test_mark_as_unique_client_noop_when_already_active(): void
    {
        $company = $this->company();
        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => '2024-05-01',
        ]);
        $company->refresh();

        app(CompanyService::class)->markAsUniqueClient($company, Carbon::parse('2026-01-01'), null);

        $company->refresh();

        // Date must NOT change
        $this->assertEquals('2024-05-01', $company->unique_client_since->toDateString());
        // No new log entry
        $this->assertSame(0, CompanyClientStatusLog::where('company_id', $company->id)->count());
    }

    // =========================================================================
    // CompanyService::disconnect
    // =========================================================================

    public function test_disconnect_sets_fields_and_log(): void
    {
        $company = $this->company();
        $company->update(['client_status' => ClientStatus::Active, 'unique_client_since' => '2025-01-01']);
        $reason = $this->reason();

        app(CompanyService::class)->disconnect($company, $reason->id, null, null);

        $company->refresh();

        $this->assertEquals(ClientStatus::Disconnected, $company->client_status);
        $this->assertNotNull($company->disconnected_at);
        $this->assertEquals($reason->id, $company->disconnect_reason_id);
        $this->assertNull($company->disconnect_doc_id);
    }

    public function test_disconnect_writes_status_log(): void
    {
        $company = $this->company();
        $company->update(['client_status' => ClientStatus::Active]);
        $reason = $this->reason();

        // N6: the listener always passes a real document ID or null.
        // Using null here to avoid FK violation with a non-existent document.
        app(CompanyService::class)->disconnect($company, $reason->id, null, null);

        $log = CompanyClientStatusLog::where('company_id', $company->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertEquals(ClientStatus::Disconnected->value, $log->new_status->value);
        $this->assertEquals($reason->id, $log->reason_id);
    }

    public function test_disconnect_stores_doc_id(): void
    {
        $company = $this->company();
        $reason = $this->reason();

        // Create a real Document so the FK constraint is satisfied.
        $doc = Document::factory()->create();

        app(CompanyService::class)->disconnect($company, $reason->id, (int) $doc->id, null);

        $this->assertEquals($doc->id, $company->fresh()->disconnect_doc_id);
    }

    // =========================================================================
    // CompanyService::reconnect
    // =========================================================================

    public function test_reconnect_returns_to_active_if_unique_client_since_set(): void
    {
        $company = $this->company();
        $reason = $this->reason();
        $service = app(CompanyService::class);

        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => '2025-01-01',
        ]);
        $service->disconnect($company, $reason->id, null, null);
        $company->refresh();
        $this->assertEquals(ClientStatus::Disconnected, $company->client_status);

        $service->reconnect($company, null);
        $company->refresh();

        $this->assertEquals(ClientStatus::Active, $company->client_status);
        $this->assertNull($company->disconnected_at);
        $this->assertNull($company->disconnect_reason_id);
        $this->assertNull($company->disconnect_doc_id);
    }

    public function test_reconnect_returns_to_prospect_if_no_unique_client_since(): void
    {
        $company = $this->company();
        $reason = $this->reason();
        $service = app(CompanyService::class);

        // Force-disconnect a prospect (no unique_client_since)
        $company->update([
            'client_status' => ClientStatus::Disconnected,
            'disconnected_at' => now(),
            'disconnect_reason_id' => $reason->id,
        ]);

        $service->reconnect($company, null);
        $company->refresh();

        $this->assertEquals(ClientStatus::Prospect, $company->client_status);
    }

    public function test_reconnect_writes_status_log(): void
    {
        $company = $this->company();
        $reason = $this->reason();
        $service = app(CompanyService::class);

        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => '2025-01-01',
        ]);
        $service->disconnect($company, $reason->id, null, null);
        $service->reconnect($company, null);

        $logCount = CompanyClientStatusLog::where('company_id', $company->id)->count();
        $this->assertSame(2, $logCount); // disconnect + reconnect

        $lastLog = CompanyClientStatusLog::where('company_id', $company->id)->latest('id')->first();
        $this->assertEquals(ClientStatus::Active->value, $lastLog->new_status->value);
    }

    // =========================================================================
    // GET /companies/{company}/status-log
    // =========================================================================

    public function test_status_log_endpoint_returns_log_entries(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();
        $service = app(CompanyService::class);

        $company->update(['client_status' => ClientStatus::Active, 'unique_client_since' => '2025-01-01']);
        $service->disconnect($company, $reason->id, null, $user->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}/status-log")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(1, 'data');
    }

    public function test_status_log_endpoint_is_ordered_newest_first(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        // Insert two log entries with explicit differing changed_at timestamps
        // to avoid sub-second collision in SQLite :memory: tests.
        CompanyClientStatusLog::create([
            'company_id' => $company->id,
            'old_status' => ClientStatus::Prospect->value,
            'new_status' => ClientStatus::Active->value,
            'changed_by' => $user->id,
            'changed_at' => now()->subSeconds(60),
            'reason_id' => null,
            'meta' => null,
        ]);

        CompanyClientStatusLog::create([
            'company_id' => $company->id,
            'old_status' => ClientStatus::Active->value,
            'new_status' => ClientStatus::Disconnected->value,
            'changed_by' => $user->id,
            'changed_at' => now(),
            'reason_id' => $reason->id,
            'meta' => null,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}/status-log")
            ->assertOk();

        $statuses = collect($response->json('data'))->pluck('new_status')->toArray();
        // Newest-first: disconnected entry should be first
        $this->assertEquals(ClientStatus::Disconnected->value, $statuses[0]);
        $this->assertEquals(ClientStatus::Active->value, $statuses[1]);
    }

    // =========================================================================
    // POST /companies/{company}/disconnect
    // =========================================================================

    public function test_disconnect_endpoint_initiates_flow_and_returns_document(): void
    {
        // N6: POST /disconnect now initiates the two-phase flow.
        // It creates a TerminationAgreement Document and DOES NOT change the company status.
        // Status changes only when TerminationAgreementSigned fires (scan uploaded).
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();
        $company->update(['client_status' => ClientStatus::Active, 'unique_client_since' => '2025-01-01']);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => $reason->id,
            'termination_date' => '2025-12-31',
        ])->assertSuccessful()
            ->assertJsonPath('data.kind', 'termination_agreement');

        // Company status MUST remain active — status changes only on signed event.
        $this->assertEquals(ClientStatus::Active, $company->fresh()->client_status);
    }

    public function test_disconnect_endpoint_requires_reason(): void
    {
        $user = $this->manager();
        $company = $this->company($user);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('disconnect_reason_id');
    }

    public function test_disconnect_endpoint_rejects_invalid_reason_id(): void
    {
        $user = $this->manager();
        $company = $this->company($user);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('disconnect_reason_id');
    }

    // =========================================================================
    // POST /companies/{company}/reconnect
    // =========================================================================

    public function test_reconnect_endpoint_returns_active_status(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => '2025-01-01',
        ]);

        app(CompanyService::class)->disconnect($company, $reason->id, null, $user->id);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/reconnect")
            ->assertOk()
            ->assertJsonPath('data.client_status', ClientStatus::Active->value);
    }

    // =========================================================================
    // CompanyResource includes client_status fields
    // =========================================================================

    public function test_company_show_includes_client_status_fields(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $company->update([
            'client_status' => ClientStatus::Active,
            'unique_client_since' => '2025-06-01',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.client_status', 'active')
            ->assertJsonPath('data.unique_client_since', '2025-06-01');
    }
}
