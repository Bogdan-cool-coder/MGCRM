<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Jobs\SendTelegramApprovalCardJob;
use App\Domain\Notification\Jobs\SendTelegramDmJob;
use App\Domain\Notification\Services\ApprovalNotificationService;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('crm.telegram.web_base_url', 'https://crm.test');
    }

    public function test_card_job_sends_and_stores_message_id(): void
    {
        $document = Document::factory()->inReview()->create(['telegram_message_id' => null]);

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendToChat')->once()->andReturn(12345);
        $this->app->instance(TelegramNotifier::class, $notifier);

        (new SendTelegramApprovalCardJob($document->id, '-100999', 'card text'))
            ->handle($notifier, app(ApprovalNotificationService::class));

        $this->assertSame(12345, (int) $document->fresh()->telegram_message_id);
    }

    public function test_card_job_is_idempotent_when_message_already_sent(): void
    {
        // Pre-check: a retry must not re-post once telegram_message_id is set.
        $document = Document::factory()->inReview()->create(['telegram_message_id' => 999]);

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendToChat');
        $this->app->instance(TelegramNotifier::class, $notifier);

        (new SendTelegramApprovalCardJob($document->id, '-100999', 'card text'))
            ->handle($notifier, app(ApprovalNotificationService::class));

        $this->assertSame(999, (int) $document->fresh()->telegram_message_id);
    }

    public function test_dm_job_sends_to_linked_user(): void
    {
        $user = User::factory()->create(['telegram_user_id' => '700700']);

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendToUser')->once()->andReturn(true);
        $this->app->instance(TelegramNotifier::class, $notifier);

        (new SendTelegramDmJob($user->id, 'verdict'))->handle($notifier);

        // The Mockery ->once() expectation is verified on tearDown; assert here too
        // so PHPUnit does not flag the test as risky.
        $this->assertTrue(true);
    }

    public function test_dm_job_is_silent_for_unlinked_user(): void
    {
        $user = User::factory()->create(['telegram_user_id' => null]);

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendToUser');
        $this->app->instance(TelegramNotifier::class, $notifier);

        // Must not throw — silent skip.
        (new SendTelegramDmJob($user->id, 'verdict'))->handle($notifier);

        $this->assertTrue(true);
    }

    public function test_jobs_are_queued_on_default(): void
    {
        $card = new SendTelegramApprovalCardJob(1, '-100999', 'x');
        $dm = new SendTelegramDmJob(1, 'x');

        $this->assertSame('default', $card->queue);
        $this->assertSame('default', $dm->queue);
        $this->assertSame(3, $card->tries);
        $this->assertSame(3, $dm->tries);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
