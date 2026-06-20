<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Events\TerminationAgreementSigned;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Listeners\DisconnectCompanyOnTerminationSigned;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyClientStatusLog;
use App\Domain\Crm\Models\DisconnectReason;
use App\Domain\Crm\Services\CompanyDisconnectService;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for N6 — two-phase disconnect flow:
 *
 *   Phase 1 (initiate):
 *     - POST /companies/{id}/disconnect → creates TerminationAgreement Document
 *     - Company status MUST remain unchanged (still active/prospect)
 *     - disconnect_reason_id stored in Document.context.custom
 *
 *   Phase 2 (listener):
 *     - TerminationAgreementSigned event → DisconnectCompanyOnTerminationSigned
 *     - Listener calls CompanyService::disconnect with correct reasonId
 *     - Listener is idempotent (already-disconnected company → no-op)
 *
 *   Reconnect (N5, unchanged):
 *     - POST /companies/{id}/reconnect reverts status
 */
class DisconnectFlowTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function manager(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    private function company(?User $owner = null): Company
    {
        $owner ??= $this->manager();

        return Company::factory()->create([
            'owner_user_id' => $owner->id,
            'client_status' => ClientStatus::Active->value,
            'unique_client_since' => '2025-01-01',
        ]);
    }

    private function reason(string $name = 'Сменил поставщика'): DisconnectReason
    {
        return DisconnectReason::create(['name' => $name, 'sort_order' => 1, 'is_active' => true]);
    }

    // =========================================================================
    // Phase 1: initiate — POST /companies/{id}/disconnect
    // =========================================================================

    public function test_initiate_creates_termination_document(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => $reason->id,
            'termination_date' => '2025-12-31',
        ])->assertSuccessful();

        // Returns a Document resource
        $response->assertJsonPath('data.kind', DocumentKind::TerminationAgreement->value);
        $response->assertJsonPath('data.status', ContractStatus::Draft->value);

        $this->assertDatabaseHas('documents', [
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $company->id,
        ]);
    }

    public function test_initiate_does_not_change_company_status(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => $reason->id,
            'termination_date' => '2025-12-31',
        ])->assertSuccessful();

        // Company MUST still be active — status changes only on signed event.
        $this->assertEquals(ClientStatus::Active, $company->fresh()->client_status);
    }

    public function test_initiate_stores_reason_id_in_document_context(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason('Бюджетные ограничения');

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => $reason->id,
            'termination_date' => '2025-12-31',
        ])->assertSuccessful();

        $docId = $response->json('data.id');
        $doc = Document::findOrFail($docId);

        $this->assertEquals($reason->id, $doc->context['custom']['disconnect_reason_id']);
        $this->assertEquals('2025-12-31', $doc->context['custom']['termination_date']);
        $this->assertEquals($reason->name, $doc->context['custom']['termination_reason']);
    }

    public function test_initiate_requires_disconnect_reason_id(): void
    {
        $user = $this->manager();
        $company = $this->company($user);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'termination_date' => '2025-12-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('disconnect_reason_id');
    }

    public function test_initiate_requires_termination_date(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => $reason->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('termination_date');
    }

    public function test_initiate_rejects_invalid_reason_id(): void
    {
        $user = $this->manager();
        $company = $this->company($user);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/disconnect", [
            'disconnect_reason_id' => 999999,
            'termination_date' => '2025-12-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('disconnect_reason_id');
    }

    // =========================================================================
    // CompanyDisconnectService unit
    // =========================================================================

    public function test_service_initiate_creates_document_and_leaves_company_active(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();
        $service = app(CompanyDisconnectService::class);

        $doc = $service->initiate($company, $reason->id, '2025-12-31', $user->id);

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertEquals(DocumentKind::TerminationAgreement, $doc->kind);
        $this->assertEquals(ContractStatus::Draft, $doc->status);
        $this->assertEquals($reason->id, $doc->context['custom']['disconnect_reason_id']);

        // Company stays active
        $this->assertEquals(ClientStatus::Active, $company->fresh()->client_status);
    }

    public function test_service_initiate_merges_caller_custom_context(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();
        $service = app(CompanyDisconnectService::class);

        $doc = $service->initiate($company, $reason->id, '2025-12-31', $user->id, [
            'context' => [
                'custom' => [
                    'original_contract_number' => 'МГ-100',
                ],
            ],
        ]);

        // Caller-supplied field is preserved
        $this->assertEquals('МГ-100', $doc->context['custom']['original_contract_number']);
        // Service-injected fields are also present
        $this->assertEquals($reason->id, $doc->context['custom']['disconnect_reason_id']);
        $this->assertEquals('2025-12-31', $doc->context['custom']['termination_date']);
    }

    // =========================================================================
    // Phase 2: listener — TerminationAgreementSigned → disconnect
    // =========================================================================

    public function test_listener_sets_company_disconnected(): void
    {
        $company = $this->company();
        $reason = $this->reason();

        // Create a termination document with reason stored in context.custom
        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $company->id,
            'status' => ContractStatus::Signed->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [
                    'disconnect_reason_id' => $reason->id,
                    'termination_date' => '2025-12-31',
                    'termination_reason' => $reason->name,
                ],
            ],
        ]);

        $event = new TerminationAgreementSigned($doc->id, $company->id, now());
        $listener = app(DisconnectCompanyOnTerminationSigned::class);
        $listener->handle($event);

        $company->refresh();

        $this->assertEquals(ClientStatus::Disconnected, $company->client_status);
        $this->assertNotNull($company->disconnected_at);
        $this->assertEquals($reason->id, $company->disconnect_reason_id);
        $this->assertEquals($doc->id, $company->disconnect_doc_id);
    }

    public function test_listener_writes_status_log_on_disconnect(): void
    {
        $company = $this->company();
        $reason = $this->reason();

        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $company->id,
            'status' => ContractStatus::Signed->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['disconnect_reason_id' => $reason->id],
            ],
        ]);

        $event = new TerminationAgreementSigned($doc->id, $company->id, now());
        $listener = app(DisconnectCompanyOnTerminationSigned::class);
        $listener->handle($event);

        $log = CompanyClientStatusLog::where('company_id', $company->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertEquals(ClientStatus::Disconnected->value, $log->new_status->value);
        $this->assertEquals($reason->id, $log->reason_id);
    }

    public function test_listener_is_idempotent_on_already_disconnected_company(): void
    {
        $company = $this->company();
        $reason = $this->reason();

        // Pre-disconnect the company
        app(CompanyService::class)->disconnect($company, $reason->id, null, null);
        $company->refresh();
        $this->assertEquals(ClientStatus::Disconnected, $company->client_status);

        $logCountBefore = CompanyClientStatusLog::where('company_id', $company->id)->count();

        // Fire event again (simulate re-upload)
        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $company->id,
            'status' => ContractStatus::Signed->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['disconnect_reason_id' => $reason->id],
            ],
        ]);

        $event = new TerminationAgreementSigned($doc->id, $company->id, now());
        $listener = app(DisconnectCompanyOnTerminationSigned::class);
        $listener->handle($event);

        // No new log entry — idempotent no-op
        $logCountAfter = CompanyClientStatusLog::where('company_id', $company->id)->count();
        $this->assertSame($logCountBefore, $logCountAfter);
        $this->assertEquals(ClientStatus::Disconnected, $company->fresh()->client_status);
    }

    public function test_listener_handles_missing_company_gracefully(): void
    {
        $reason = $this->reason();

        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'status' => ContractStatus::Signed->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['disconnect_reason_id' => $reason->id],
            ],
        ]);

        // Non-existent companyId — listener must not throw
        $event = new TerminationAgreementSigned($doc->id, 999999, now());
        $listener = app(DisconnectCompanyOnTerminationSigned::class);

        // Should complete without exception
        $listener->handle($event);

        $this->assertTrue(true); // assert no exception thrown
    }

    // =========================================================================
    // Full event-dispatch integration
    // =========================================================================

    public function test_event_dispatch_triggers_listener_and_disconnects_company(): void
    {
        $company = $this->company();
        $reason = $this->reason();

        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $company->id,
            'status' => ContractStatus::Signed->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['disconnect_reason_id' => $reason->id],
            ],
        ]);

        // Dispatch the event through the real event system
        event(new TerminationAgreementSigned($doc->id, $company->id, now()));

        $company->refresh();
        $this->assertEquals(ClientStatus::Disconnected, $company->client_status);
    }

    // =========================================================================
    // Reconnect (N5, unchanged)
    // =========================================================================

    public function test_reconnect_after_event_driven_disconnect(): void
    {
        $user = $this->manager();
        $company = $this->company($user);
        $reason = $this->reason();

        // Directly set disconnected (simulating listener result)
        app(CompanyService::class)->disconnect($company, $reason->id, null, null);
        $company->refresh();

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/reconnect")
            ->assertOk()
            ->assertJsonPath('data.client_status', ClientStatus::Active->value);

        $this->assertEquals(ClientStatus::Active, $company->fresh()->client_status);
        $this->assertNull($company->fresh()->disconnect_reason_id);
        $this->assertNull($company->fresh()->disconnected_at);
    }
}
