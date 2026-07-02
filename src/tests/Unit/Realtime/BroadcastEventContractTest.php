<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Events\ActivityCreated;
use App\Domain\Activity\Events\ActivityDeleted;
use App\Domain\Activity\Events\ActivityStatusChanged;
use App\Domain\Activity\Events\ActivityUpdated;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Events\CompanyCreated;
use App\Domain\Crm\Events\CompanyDeleted;
use App\Domain\Crm\Events\ContactCreated;
use App\Domain\Crm\Events\ContactDeleted;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Events\DealDeleted;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Events\DealUpdated;
use App\Domain\Sales\Models\Deal;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realtime contract (Phase 7a): every priority domain event must implement
 * ShouldBroadcast, expose the agreed broadcastAs() name, and route to the exact
 * channel set the frontend + docker contract promises. These assertions ARE the
 * contract — a rename/re-route here is a breaking change to the frontend.
 */
class BroadcastEventContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function channelNames(object $event): array
    {
        return array_map(
            static fn (PrivateChannel $c): string => $c->name,
            $event->broadcastOn(),
        );
    }

    public function test_deal_created_broadcasts_on_entity_and_department_channels(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $deal = Deal::factory()->create(['department_id' => $dept->id]);

        $event = new DealCreated($deal);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('deal.created', $event->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-deal.'.$deal->id, 'private-dept.'.$dept->id.'.deals'],
            $this->channelNames($event),
        );
        $this->assertSame($deal->id, $event->broadcastWith()['id']);
        // Amount is integer kopecks, no PII fields leak.
        $this->assertArrayHasKey('amount', $event->broadcastWith());
        $this->assertArrayNotHasKey('title', $event->broadcastWith());
    }

    public function test_deal_without_department_only_hits_entity_channel(): void
    {
        $deal = Deal::factory()->create(['department_id' => null]);

        $event = new DealCreated($deal);

        $this->assertSame(['private-deal.'.$deal->id], $this->channelNames($event));
    }

    public function test_deal_stage_changed_carries_transition_and_channels(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $deal = Deal::factory()->create(['department_id' => $dept->id]);

        $event = new DealStageChanged($deal, 10, 20, now()->toISOString());

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('deal.stage_changed', $event->broadcastAs());
        $this->assertSame(10, $event->broadcastWith()['from_stage_id']);
        $this->assertSame(20, $event->broadcastWith()['to_stage_id']);
        $this->assertEqualsCanonicalizing(
            ['private-deal.'.$deal->id, 'private-dept.'.$dept->id.'.deals'],
            $this->channelNames($event),
        );
    }

    public function test_deal_updated_and_deleted_contracts(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $deal = Deal::factory()->create(['department_id' => $dept->id]);

        $updated = new DealUpdated($deal);
        $this->assertInstanceOf(ShouldBroadcast::class, $updated);
        $this->assertSame('deal.updated', $updated->broadcastAs());

        $deleted = new DealDeleted($deal->id, $dept->id);
        $this->assertInstanceOf(ShouldBroadcast::class, $deleted);
        $this->assertSame('deal.deleted', $deleted->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-deal.'.$deal->id, 'private-dept.'.$dept->id.'.deals'],
            $this->channelNames($deleted),
        );
        $this->assertSame($deal->id, $deleted->broadcastWith()['id']);
    }

    public function test_activity_on_deal_broadcasts_entity_user_and_dept_task_channels(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $responsible = User::factory()->create(['department_id' => $dept->id]);
        $deal = Deal::factory()->create(['department_id' => $dept->id]);
        $activity = Activity::factory()->forDeal($deal)->create([
            'responsible_id' => $responsible->id,
            'department_id' => $dept->id,
        ]);

        $event = new ActivityCreated($activity);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('activity.created', $event->broadcastAs());
        $this->assertEqualsCanonicalizing(
            [
                'private-deal.'.$deal->id,
                'private-user.'.$responsible->id,
                'private-dept.'.$dept->id.'.tasks',
            ],
            $this->channelNames($event),
        );
        // PII-safe: no body/title in the payload.
        $this->assertArrayNotHasKey('body', $event->broadcastWith());
        $this->assertArrayNotHasKey('title', $event->broadcastWith());
    }

    public function test_standalone_activity_has_no_entity_channel(): void
    {
        $responsible = User::factory()->create();
        $activity = Activity::factory()->standalone()->create([
            'responsible_id' => $responsible->id,
            'department_id' => null,
        ]);

        $event = new ActivityCreated($activity);

        // Only the responsible user's personal channel — no target, no dept.
        $this->assertSame(['private-user.'.$responsible->id], $this->channelNames($event));
    }

    public function test_activity_status_changed_carries_transition(): void
    {
        $responsible = User::factory()->create();
        $activity = Activity::factory()->task()->create(['responsible_id' => $responsible->id]);

        $event = new ActivityStatusChanged($activity, ActivityStatus::New, ActivityStatus::Done);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('activity.status_changed', $event->broadcastAs());
        $this->assertSame('new', $event->broadcastWith()['from']);
        $this->assertSame('done', $event->broadcastWith()['to']);
    }

    public function test_activity_updated_and_deleted_contracts(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $responsible = User::factory()->create(['department_id' => $dept->id]);
        $deal = Deal::factory()->create(['department_id' => $dept->id]);
        $activity = Activity::factory()->forDeal($deal)->create([
            'responsible_id' => $responsible->id,
            'department_id' => $dept->id,
        ]);

        $updated = new ActivityUpdated($activity);
        $this->assertInstanceOf(ShouldBroadcast::class, $updated);
        $this->assertSame('activity.updated', $updated->broadcastAs());

        $deleted = new ActivityDeleted($activity->id, 'deal', $deal->id, $responsible->id, $dept->id);
        $this->assertInstanceOf(ShouldBroadcast::class, $deleted);
        $this->assertSame('activity.deleted', $deleted->broadcastAs());
        $this->assertEqualsCanonicalizing(
            [
                'private-deal.'.$deal->id,
                'private-user.'.$responsible->id,
                'private-dept.'.$dept->id.'.tasks',
            ],
            $this->channelNames($deleted),
        );
    }

    public function test_company_events_route_to_entity_and_dept_contacts(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $company = Company::factory()->create(['department_id' => $dept->id]);

        $created = new CompanyCreated($company);
        $this->assertInstanceOf(ShouldBroadcast::class, $created);
        $this->assertSame('company.created', $created->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-company.'.$company->id, 'private-dept.'.$dept->id.'.contacts'],
            $this->channelNames($created),
        );

        $deleted = new CompanyDeleted($company->id, $dept->id);
        $this->assertSame('company.deleted', $deleted->broadcastAs());
        $this->assertSame($company->id, $deleted->broadcastWith()['id']);
    }

    public function test_contact_events_anchor_on_owner_department(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $owner = User::factory()->create(['department_id' => $dept->id]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);

        $created = new ContactCreated($contact, $dept->id);
        $this->assertInstanceOf(ShouldBroadcast::class, $created);
        $this->assertSame('contact.created', $created->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-contact.'.$contact->id, 'private-dept.'.$dept->id.'.contacts'],
            $this->channelNames($created),
        );

        // No owner department → entity channel only.
        $ownerless = new ContactCreated($contact, null);
        $this->assertSame(['private-contact.'.$contact->id], $this->channelNames($ownerless));

        $deleted = new ContactDeleted($contact->id, $dept->id);
        $this->assertSame('contact.deleted', $deleted->broadcastAs());
    }
}
