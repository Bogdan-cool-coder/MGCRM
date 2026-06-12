<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Contracts\Services\RemarkService;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemarkServiceTest extends TestCase
{
    use RefreshDatabase;

    private RemarkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RemarkService::class);
    }

    public function test_create_for_decision_creates_remark_with_attempt(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->inReview()->create();

        $remark = $this->service->createForDecision($doc, $user->id, 2, 1, 'Missing signature.');

        $this->assertSame(2, $remark->attempt);
        $this->assertSame(1, $remark->stage_order);
        $this->assertSame('Missing signature.', $remark->text);
        $this->assertFalse($remark->is_resolved);
        $this->assertDatabaseHas('document_remarks', [
            'document_id' => $doc->id,
            'attempt' => 2,
            'stage_order' => 1,
            'author_user_id' => $user->id,
        ]);
    }

    public function test_create_for_decision_throws_on_empty_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();

        $this->service->createForDecision($doc, $user->id, 1, 0, '   ');
    }

    public function test_create_for_decision_throws_on_blank_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();

        $this->service->createForDecision($doc, $user->id, 1, 0, '');
    }

    public function test_toggle_resolve_marks_resolved(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);

        $updated = $this->service->toggleResolve($remark, $user);

        $this->assertTrue($updated->is_resolved);
        $this->assertNotNull($updated->resolved_at);
        $this->assertSame($user->id, $updated->resolved_by_user_id);
    }

    public function test_toggle_resolve_twice_clears_resolved(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);

        // First toggle: mark resolved
        $this->service->toggleResolve($remark, $user);
        $remark->refresh();
        $this->assertTrue($remark->is_resolved);

        // Second toggle: clear resolved
        $updated = $this->service->toggleResolve($remark, $user);

        $this->assertFalse($updated->is_resolved);
        $this->assertNull($updated->resolved_at);
        $this->assertNull($updated->resolved_by_user_id);
    }

    public function test_list_for_document_returns_all_remarks(): void
    {
        $doc = Document::factory()->draft()->create();

        DocumentRemark::factory()->count(3)->create(['document_id' => $doc->id, 'attempt' => 1]);
        DocumentRemark::factory()->count(2)->create(['document_id' => $doc->id, 'attempt' => 2]);

        $all = $this->service->listForDocument($doc);
        $this->assertCount(5, $all);
    }

    public function test_list_for_document_filters_by_attempt(): void
    {
        $doc = Document::factory()->draft()->create();

        DocumentRemark::factory()->count(3)->create(['document_id' => $doc->id, 'attempt' => 1]);
        DocumentRemark::factory()->count(2)->create(['document_id' => $doc->id, 'attempt' => 2]);

        $filtered = $this->service->listForDocument($doc, 1);
        $this->assertCount(3, $filtered);
    }
}
