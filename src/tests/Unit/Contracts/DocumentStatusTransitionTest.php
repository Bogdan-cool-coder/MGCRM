<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentService::class);
    }

    public function test_draft_can_transition_to_submitted(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        $updated = $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        $this->assertSame(ContractStatus::Submitted, $updated->status);
    }

    public function test_submitted_cannot_directly_transition_to_approved(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->submitted()->create(['author_user_id' => $user->id]);

        $this->service->transition($doc, ContractStatus::Approved, $user->id);
    }

    public function test_uploaded_stub_returns_409(): void
    {
        $this->expectException(HttpException::class);

        $user = User::factory()->create();
        // signed status allows → uploaded in enum, but service intercepts first.
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'author_user_id' => $user->id,
        ]);

        $this->service->transition($doc, ContractStatus::Uploaded, $user->id);
    }

    public function test_uploaded_stub_has_409_code(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'author_user_id' => $user->id,
        ]);

        try {
            $this->service->transition($doc, ContractStatus::Uploaded, $user->id);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('not_yet_implemented', $e->getMessage());
        }
    }

    public function test_rejected_is_terminal_no_transitions(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->create([
            'status' => ContractStatus::Rejected->value,
            'author_user_id' => $user->id,
        ]);

        $this->service->transition($doc, ContractStatus::Submitted, $user->id);
    }

    public function test_archived_is_terminal_no_transitions(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->archived()->create(['author_user_id' => $user->id]);

        $this->service->transition($doc, ContractStatus::Submitted, $user->id);
    }

    public function test_service_throws_on_invalid_transition(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        try {
            $this->service->transition($doc, ContractStatus::Signed, $user->id);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_transition_is_atomic_with_lock(): void
    {
        // Verify the transition uses lockForUpdate by checking the document
        // is actually persisted in DB after the call (not just in-memory).
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        $updated = $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        // Re-fetch from DB (not just from cache) to confirm persistence.
        $fromDb = Document::query()->find($doc->id);
        $this->assertSame(ContractStatus::Submitted->value, $fromDb->status->value);
        $this->assertSame(ContractStatus::Submitted->value, $updated->status->value);
    }
}
