<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Jobs\SendTelegramApprovalCardJob;
use App\Domain\Notification\Jobs\SendTelegramDmJob;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ApprovalListenersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('crm.telegram.approval_chat_id', '-100999');
        config()->set('crm.telegram.web_base_url', 'https://crm.test');
    }

    public function test_listeners_are_registered_for_both_events(): void
    {
        // The listeners are wired in AppServiceProvider::boot (not Event::fake'd here),
        // so assert against the real dispatcher's registered listeners.
        $this->assertTrue(Event::hasListeners(DocumentSubmittedForApproval::class));
        $this->assertTrue(Event::hasListeners(ApprovalDecisionMade::class));
    }

    public function test_submitted_event_dispatches_card_job(): void
    {
        Bus::fake();
        $document = Document::factory()->inReview()->create();
        $route = ApprovalRoute::factory()->create();

        event(new DocumentSubmittedForApproval(
            $document,
            $route,
            ['order' => 1, 'name' => 'Юрист', 'user_ids' => [1], 'min_required' => 1],
            submittedBy: 1,
            attempt: 1,
        ));

        Bus::assertDispatched(SendTelegramApprovalCardJob::class);
    }

    public function test_final_verdict_dispatches_author_dm(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create(['author_user_id' => $author->id]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);

        event(new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Approved,
            ContractStatus::Approved,
        ));

        Bus::assertDispatched(SendTelegramDmJob::class);
    }

    public function test_intermediate_approve_does_not_dm_author(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create(['author_user_id' => $author->id]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);

        // Quorum not yet reached → document stays InReview → no DM.
        event(new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Approved,
            ContractStatus::InReview,
        ));

        Bus::assertNotDispatched(SendTelegramDmJob::class);
    }

    public function test_rejected_verdict_dispatches_author_dm(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create(['author_user_id' => $author->id]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Rejected->value,
            'comment' => 'Причина',
        ]);

        event(new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Rejected,
            ContractStatus::Rejected,
        ));

        Bus::assertDispatched(SendTelegramDmJob::class);
    }

    public function test_final_verdict_creates_in_app_notification_for_author(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create([
            'author_user_id' => $author->id,
            'number' => 'D-42',
        ]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);

        event(new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Approved,
            ContractStatus::Approved,
        ));

        // TG DM still fires.
        Bus::assertDispatched(SendTelegramDmJob::class);

        // AND the author now gets an in-app verdict row.
        $notification = Notification::query()
            ->where('user_id', $author->id)
            ->where('category', NotificationCategory::Approval->value)
            ->first();

        $this->assertNotNull($notification, 'author should receive an in-app verdict notification');
        $this->assertSame('Договор согласован № D-42', $notification->title);
        $this->assertSame('/documents/'.$document->id, $notification->deep_link);
        $this->assertSame($document->id, $notification->data['document_id']);
        $this->assertSame(ContractStatus::Approved->value, $notification->data['verdict']);
        $this->assertFalse($notification->is_actionable);
    }

    public function test_in_app_verdict_is_idempotent_per_verdict(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create(['author_user_id' => $author->id]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);

        $verdict = new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Approved,
            ContractStatus::Approved,
        );

        event($verdict);
        event($verdict); // re-emit same verdict → no duplicate bell.

        $count = Notification::query()
            ->where('user_id', $author->id)
            ->where('category', NotificationCategory::Approval->value)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_intermediate_approve_creates_no_in_app_notification(): void
    {
        Bus::fake();
        $author = User::factory()->create();
        $document = Document::factory()->create(['author_user_id' => $author->id]);
        $approval = Approval::factory()->create([
            'document_id' => $document->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);

        event(new ApprovalDecisionMade(
            $document,
            $approval,
            ApprovalDecision::Approved,
            ContractStatus::InReview,
        ));

        $this->assertSame(0, Notification::query()->where('user_id', $author->id)->count());
    }
}
