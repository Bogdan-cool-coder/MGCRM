<?php

declare(strict_types=1);

namespace Tests\Feature\Log;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Enums\ContactStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\DealContactService;
use App\Domain\Sales\Services\DealMoveService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class EntityLogRecordingTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * @return array{0: User, 1: Pipeline}
     */
    private function setup_pipeline(): array
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->seedSalesPipeline();

        return [$user, $pipeline];
    }

    private function dealLogs(int $dealId): Collection
    {
        return EntityLog::query()
            ->where('subject_type', 'deal')
            ->where('subject_id', $dealId)
            ->get();
    }

    // ---- Deal: created ----

    public function test_deal_create_records_created_event(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $company = Company::factory()->create();

        $deal = app(DealService::class)->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'title' => 'New deal',
            'currency' => 'RUB',
        ], $user);

        $logs = $this->dealLogs((int) $deal->id);
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('created', $log->action->value);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('New deal', $log->meta['title']);
    }

    // ---- Deal: stage_changed ----

    public function test_deal_move_records_stage_changed_event(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        EntityLog::query()->delete(); // ignore the create-time row if any

        $toStage = $this->stageCode($pipeline, 'qualify');
        app(DealMoveService::class)->move($deal, $toStage, $user->id);

        $stageEvents = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'stage_changed');
        $this->assertCount(1, $stageEvents);
        $event = $stageEvents->first();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($toStage, $event->meta['to_stage_id']);
        $this->assertSame('Квалификация', $event->meta['to_stage_name']);
    }

    public function test_deal_move_noop_records_nothing(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $stage = $this->stageCode($pipeline, 'new');
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage,
        ]);
        EntityLog::query()->delete();

        // Moving to the same stage is a no-op → no stage_changed row.
        app(DealMoveService::class)->move($deal, $stage, $user->id);

        $this->assertCount(0, $this->dealLogs((int) $deal->id)->filter(
            fn (EntityLog $l) => $l->action->value === 'stage_changed',
        ));
    }

    // ---- Deal: contact_added ----

    public function test_deal_add_contact_records_contact_added_event(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $company = Company::factory()->create();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);
        $contact = Contact::factory()->create(['full_name' => 'Jane Buyer']);
        EntityLog::query()->delete();

        app(DealContactService::class)->addContact($deal, (int) $contact->id, true, $user);

        $events = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'contact_added');
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame($contact->id, $event->meta['contact_id']);
        $this->assertSame('Jane Buyer', $event->meta['contact_name']);
        $this->assertTrue($event->meta['is_primary']);
    }

    // ---- Deal: data_changed ----

    public function test_deal_update_records_data_changed_event(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Old title',
        ]);
        EntityLog::query()->delete();

        app(DealService::class)->update($deal, ['title' => 'New title'], $user);

        $events = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'data_changed');
        $this->assertCount(1, $events);
        $changes = $events->first()->meta['changes'];
        $this->assertSame('title', $changes[0]['field']);
        $this->assertSame('Old title', $changes[0]['old']);
        $this->assertSame('New title', $changes[0]['new']);
    }

    public function test_deal_update_with_no_changes_records_nothing(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Same',
        ]);
        EntityLog::query()->delete();

        app(DealService::class)->update($deal, ['title' => 'Same'], $user);

        $this->assertCount(0, $this->dealLogs((int) $deal->id)->filter(
            fn (EntityLog $l) => $l->action->value === 'data_changed',
        ));
    }

    // ---- Activity: meeting_held / task_completed ----

    public function test_meeting_completion_records_meeting_held_on_deal(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $activity = Activity::factory()->meeting()->forDeal($deal)->create([
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
        ]);
        EntityLog::query()->delete();

        app(ActivityService::class)->complete($activity, $user);

        $events = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'meeting_held');
        $this->assertCount(1, $events);
        $this->assertSame((int) $activity->id, $events->first()->meta['activity_id']);
    }

    public function test_task_completion_records_task_completed_on_deal(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $activity = Activity::factory()->task()->forDeal($deal)->create([
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
        ]);
        EntityLog::query()->delete();

        app(ActivityService::class)->complete($activity, $user);

        $events = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'task_completed');
        $this->assertCount(1, $events);
    }

    public function test_standalone_task_completion_records_nothing(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $activity = Activity::factory()->task()->standalone()->create([
            'responsible_id' => $user->id,
            'created_by_id' => $user->id,
        ]);

        app(ActivityService::class)->complete($activity, $user);

        // No subject (target-less personal task) → no entity-log row at all.
        $this->assertSame(0, EntityLog::query()->count());
    }

    // ---- Company: data_changed / contact_added ----

    public function test_company_update_records_data_changed(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create(['name' => 'Acme']);

        app(CompanyService::class)->update($company, ['name' => 'Acme Corp'], $user);

        $events = EntityLog::query()
            ->where('subject_type', 'company')
            ->where('subject_id', $company->id)
            ->where('action', 'data_changed')
            ->get();
        $this->assertCount(1, $events);
        $this->assertSame('name', $events->first()->meta['changes'][0]['field']);
    }

    public function test_company_add_employee_records_contact_added(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['full_name' => 'Bob Worker']);

        app(CompanyService::class)->addEmployee($company, (int) $contact->id, ['is_primary' => true], $user);

        $events = EntityLog::query()
            ->where('subject_type', 'company')
            ->where('subject_id', $company->id)
            ->where('action', 'contact_added')
            ->get();
        $this->assertCount(1, $events);
        $this->assertSame('Bob Worker', $events->first()->meta['contact_name']);
    }

    // ---- Contact: created / data_changed ----

    private function contactLogs(int $contactId): Collection
    {
        return EntityLog::query()
            ->where('subject_type', 'contact')
            ->where('subject_id', $contactId)
            ->get();
    }

    public function test_contact_create_records_created_event(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);

        $contact = app(ContactService::class)->create([
            'full_name' => 'New Contact',
            'email' => 'new@example.test',
        ], $user);

        $logs = $this->contactLogs((int) $contact->id);
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('created', $log->action->value);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('New Contact', $log->meta['title']);
    }

    public function test_contact_update_records_data_changed(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['full_name' => 'Old Name']);
        EntityLog::query()->delete();

        app(ContactService::class)->update($contact, ['full_name' => 'New Name'], $user);

        $events = $this->contactLogs((int) $contact->id)
            ->filter(fn (EntityLog $l) => $l->action->value === 'data_changed');
        $this->assertCount(1, $events);
        $changes = $events->first()->meta['changes'];
        $this->assertSame('full_name', $changes[0]['field']);
        $this->assertSame('Old Name', $changes[0]['old']);
        $this->assertSame('New Name', $changes[0]['new']);
    }

    public function test_contact_update_with_no_changes_records_nothing(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['full_name' => 'Same Name']);
        EntityLog::query()->delete();

        app(ContactService::class)->update($contact, ['full_name' => 'Same Name'], $user);

        $this->assertCount(0, $this->contactLogs((int) $contact->id)->filter(
            fn (EntityLog $l) => $l->action->value === 'data_changed',
        ));
    }

    public function test_contact_update_unchanged_enum_status_records_nothing(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['status' => ContactStatus::Active->value]);
        EntityLog::query()->delete();

        // Submitting the same status as a string must not be seen as a change
        // (enum-cast original vs string incoming is normalized before compare).
        app(ContactService::class)->update($contact, ['status' => ContactStatus::Active->value], $user);

        $this->assertCount(0, $this->contactLogs((int) $contact->id)->filter(
            fn (EntityLog $l) => $l->action->value === 'data_changed',
        ));
    }

    public function test_contact_update_changed_enum_status_records_data_changed(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['status' => ContactStatus::Active->value]);
        EntityLog::query()->delete();

        app(ContactService::class)->update($contact, ['status' => ContactStatus::Archived->value], $user);

        $events = $this->contactLogs((int) $contact->id)
            ->filter(fn (EntityLog $l) => $l->action->value === 'data_changed');
        $this->assertCount(1, $events);
        $changes = $events->first()->meta['changes'];
        $this->assertSame('status', $changes[0]['field']);
        $this->assertSame('active', $changes[0]['old']);
        $this->assertSame('archived', $changes[0]['new']);
    }

    // ---- Contracts extension point: contract_event ----

    public function test_contract_transition_records_contract_event_on_source_deal(): void
    {
        [$user, $pipeline] = $this->setup_pipeline();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $doc = Document::factory()->create([
            'source_deal_id' => $deal->id,
            'status' => ContractStatus::Draft->value,
        ]);
        EntityLog::query()->delete();

        app(DocumentService::class)->transition($doc, ContractStatus::Submitted, $user->id);

        $events = $this->dealLogs((int) $deal->id)->filter(fn (EntityLog $l) => $l->action->value === 'contract_event');
        $this->assertCount(1, $events);
        $this->assertSame('submitted', $events->first()->meta['status']);
        $this->assertSame((int) $doc->id, $events->first()->meta['document_id']);
    }
}
