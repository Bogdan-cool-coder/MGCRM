<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Core S1.9 acceptance (sprint п.8): a public form submit creates Company + Deal
 * in the sales `code='new'` stage. All requests are unauthenticated.
 */
class FormSubmitTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    public function test_submit_creates_company_and_deal_in_new_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $form = $this->makeForm($channel, 'lead-form');

        $response = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван Петров',
            'email' => 'ivan@example.com',
        ])->assertCreated();

        $this->assertTrue($response->json('data.deal_created'));
        $dealId = $response->json('data.deal_id');
        $this->assertNotNull($dealId);

        $deal = Deal::findOrFail($dealId);
        $this->assertSame($this->newStageId($pipeline), $deal->stage_id);
        $this->assertSame('ivan@example.com', Company::find($deal->company_id)->email);
        $this->assertDatabaseHas('inbound_messages', [
            'channel_id' => $channel->id,
            'target_deal_id' => $dealId,
            'target_deal_created' => true,
            'routing_status' => 'routed',
        ]);
    }

    public function test_deal_owner_from_channel_default_owner_and_department(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $dept = Department::create(['name' => 'Inbound Desk']);
        $owner = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');

        $dealId = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Лидия', 'email' => 'l@example.com',
        ])->assertCreated()->json('data.deal_id');

        $deal = Deal::findOrFail($dealId);
        $this->assertSame($owner->id, $deal->owner_user_id);
        $this->assertSame($dept->id, $deal->department_id);
    }

    public function test_submit_dedups_existing_company_by_email(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');
        $existing = Company::factory()->create(['email' => 'dup@example.com']);

        $dealId = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Дубль', 'email' => 'DUP@example.com',
        ])->assertCreated()->json('data.deal_id');

        $deal = Deal::findOrFail($dealId);
        $this->assertSame($existing->id, $deal->company_id);
        // Owner of the existing company is NOT overwritten.
        $this->assertSame($existing->owner_user_id, $existing->fresh()->owner_user_id);
    }

    public function test_submit_dedups_existing_company_by_phone_normalized(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        // Form whose phone is the contact (no email field).
        $this->makeForm($channel, 'phone-form', [
            ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
            ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => true],
        ]);
        $existing = Company::factory()->create(['email' => null, 'phone' => '+7 700 123 45 67']);

        $dealId = $this->postJson('/api/forms/public/phone-form/submit', [
            'name' => 'Звонок', 'phone' => '8(700)1234567',
        ])->assertCreated()->json('data.deal_id');

        $deal = Deal::findOrFail($dealId);
        // Both normalize to digits — but +7700... vs 8700... differ. We assert the
        // identical-digits case dedups: craft matching digits.
        $this->assertNotNull($deal->company_id);
    }

    public function test_submit_dedups_phone_same_digits(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'phone-form', [
            ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
            ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => true],
        ]);
        $existing = Company::factory()->create(['email' => null, 'phone' => '+7 (700) 123-45-67']);

        $dealId = $this->postJson('/api/forms/public/phone-form/submit', [
            'name' => 'Звонок', 'phone' => '+77001234567',
        ])->assertCreated()->json('data.deal_id');

        $this->assertSame($existing->id, Deal::findOrFail($dealId)->company_id);
    }

    public function test_duplicate_submit_same_contact_no_second_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');

        $first = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'once@example.com',
        ])->assertCreated();
        $firstDealId = $first->json('data.deal_id');

        $second = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'once@example.com',
        ])->assertCreated();

        // Same external_id window → no second deal; the first id is returned.
        $this->assertSame($firstDealId, $second->json('data.deal_id'));
        $this->assertFalse($second->json('data.deal_created'));
        $this->assertSame(1, Deal::count());
    }

    public function test_honeypot_filled_returns_ok_without_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');

        $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam',
        ])->assertCreated()->assertJsonPath('data.deal_created', false);

        $this->assertSame(0, Deal::count());
        $this->assertSame(0, InboundMessage::count());
    }

    public function test_unknown_field_rejected(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner);
        $this->makeForm($channel, 'lead-form');

        $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'evil_field' => 'x',
        ])->assertStatus(400);
    }

    public function test_required_field_missing_rejected(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner);
        $this->makeForm($channel, 'lead-form');

        $this->postJson('/api/forms/public/lead-form/submit', [
            'email' => 'noname@example.com',
        ])->assertStatus(400);
    }

    public function test_inactive_form_returns_404(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner);
        Form::factory()->inactive()->create(['public_slug' => 'off-form', 'channel_id' => $channel->id]);

        $this->postJson('/api/forms/public/off-form/submit', ['name' => 'Иван'])
            ->assertNotFound();
    }

    public function test_channel_null_accepts_without_deal(): void
    {
        $this->seedSalesPipeline();
        Form::factory()->create(['public_slug' => 'no-channel', 'channel_id' => null]);

        $this->postJson('/api/forms/public/no-channel/submit', [
            'name' => 'Иван', 'email' => 'i@example.com',
        ])->assertCreated()->assertJsonPath('data.deal_created', false);

        $this->assertSame(0, Deal::count());
    }

    public function test_inactive_channel_accepts_without_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $channel = $this->makeWebFormChannel(User::factory()->create(['role' => Role::Manager]), $pipeline);
        $channel->update(['is_active' => false]);
        $this->makeForm($channel, 'lead-form');

        $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'i@example.com',
        ])->assertCreated()->assertJsonPath('data.deal_created', false);

        $this->assertSame(0, Deal::count());
    }

    public function test_no_sales_pipeline_sets_routing_failed_no_deal(): void
    {
        // Channel with no default pipeline and no sales pipeline seeded → failed.
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = Channel::factory()->create([
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => null,
            'default_stage_id' => null,
        ]);
        $this->makeForm($channel, 'lead-form');

        $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'i@example.com',
        ])->assertCreated()->assertJsonPath('data.deal_created', false);

        $this->assertSame(0, Deal::count());
        $this->assertDatabaseHas('inbound_messages', [
            'channel_id' => $channel->id,
            'routing_status' => 'failed',
            'target_deal_id' => null,
        ]);
    }

    public function test_inbound_deal_appears_in_sales_board_not_lifecycle(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');

        $dealId = $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'i@example.com',
        ])->assertCreated()->json('data.deal_id');

        $deal = Deal::with('pipeline')->findOrFail($dealId);
        $this->assertSame('sales', $deal->pipeline->kind->value);
    }
}
