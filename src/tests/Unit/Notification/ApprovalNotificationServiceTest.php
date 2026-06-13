<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Jobs\SendTelegramApprovalCardJob;
use App\Domain\Notification\Services\ApprovalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ApprovalNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('crm.telegram.web_base_url', 'https://crm.test');
        config()->set('crm.telegram.approval_chat_id', '-100999');
        $this->service = app(ApprovalNotificationService::class);
    }

    public function test_card_contains_company_number_country_product_author(): void
    {
        $author = User::factory()->create(['full_name' => 'Иван Петров']);
        $document = Document::factory()->create([
            'title' => 'ООО Ромашка',
            'number' => 'KZ-2026-007',
            'country_code' => 'kz',
            'product_code' => 'macrocrm',
            'author_user_id' => $author->id,
        ]);

        $card = $this->service->buildCard($document->load('author'), [
            'order' => 1,
            'name' => 'Юрист',
        ]);

        $this->assertStringContainsString('ООО Ромашка', $card);
        $this->assertStringContainsString('KZ-2026-007', $card);
        $this->assertStringContainsString('Казахстан', $card);
        $this->assertStringContainsString('Иван Петров', $card);
        $this->assertStringContainsString('https://crm.test/documents/'.$document->id, $card);
    }

    public function test_card_without_number_shows_placeholder(): void
    {
        $document = Document::factory()->create(['number' => null]);

        $card = $this->service->buildCard($document->load('author'), ['order' => 1, 'name' => 'Юрист']);

        $this->assertStringContainsString('(номер не присвоен)', $card);
    }

    public function test_stage_header_shown_only_for_non_first_stage(): void
    {
        $document = Document::factory()->create();

        $first = $this->service->buildCard($document->load('author'), ['order' => 1, 'name' => 'Этап 1']);
        $second = $this->service->buildCard($document->load('author'), ['order' => 2, 'name' => 'Директор']);

        $this->assertStringNotContainsString('➡️ Этап:', $first);
        $this->assertStringContainsString('➡️ Этап: Директор', $second);
    }

    public function test_keyboard_has_three_buttons_with_correct_callback_data(): void
    {
        $keyboard = $this->service->buildKeyboard(42);
        $json = json_encode($keyboard->inline_keyboard, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('apv:approve:42', $json);
        $this->assertStringContainsString('apv:reject:42', $json);
        $this->assertStringContainsString('apv:rework:42', $json);
        $this->assertStringContainsString('✅ Согласовать', $json);
        $this->assertStringContainsString('❌ Отклонить', $json);
        $this->assertStringContainsString('🔁 На доработку', $json);
        // Two rows: [approve, reject] then [rework].
        $this->assertCount(2, $keyboard->inline_keyboard);
    }

    public function test_card_text_has_no_secrets(): void
    {
        config()->set('crm.telegram.bot_token', 'SUPER-SECRET-TOKEN');
        $author = User::factory()->create([
            'full_name' => 'Иван Петров',
            'email' => 'secret.person@example.com',
        ]);
        $document = Document::factory()->create(['author_user_id' => $author->id]);

        $card = $this->service->buildCard($document->load('author'), ['order' => 1, 'name' => 'Юрист']);

        $this->assertStringNotContainsString('secret.person@example.com', $card);
        $this->assertStringNotContainsString('SUPER-SECRET-TOKEN', $card);
    }

    public function test_notify_stage_dispatches_card_job(): void
    {
        Bus::fake();
        $document = Document::factory()->create();

        $this->service->notifyStage($document->load('author'), ['order' => 1, 'name' => 'Юрист'], 1);

        Bus::assertDispatched(SendTelegramApprovalCardJob::class);
    }

    public function test_notify_stage_skips_when_no_chat_configured(): void
    {
        Bus::fake();
        config()->set('crm.telegram.approval_chat_id', '');
        $document = Document::factory()->create();

        $this->service->notifyStage($document->load('author'), ['order' => 1, 'name' => 'Юрист'], 1);

        Bus::assertNotDispatched(SendTelegramApprovalCardJob::class);
    }
}
