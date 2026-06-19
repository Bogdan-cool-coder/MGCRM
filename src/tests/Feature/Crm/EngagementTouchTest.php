<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Services\DealMoveService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Activity\ActivityTestHelpers;
use Tests\TestCase;

/**
 * Integration coverage for the engagement signal wiring (Контакты 2.0 §B2):
 * EngagementService::touch() must fire — stamping last_activity_at on the right
 * Crm rows — whenever a relevant Activity or Deal event happens. Services are
 * resolved from the container so the real DI graph is exercised end-to-end
 * (proving the Sales↔Activity↔Crm injection has no circular dependency).
 */
class EngagementTouchTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    private ActivityService $activities;

    private DealService $deals;

    private DealMoveService $moves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activities = app(ActivityService::class);
        $this->deals = app(DealService::class);
        $this->moves = app(DealMoveService::class);
    }

    // ---- Activity domain ----

    public function test_creating_company_activity_touches_company(): void
    {
        $director = $this->director();
        $company = $this->companyFor($director);

        $this->assertNull($company->last_activity_at);

        $this->activities->create([
            'kind' => 'note',
            'title' => 'Called the company',
            'target_type' => 'company',
            'target_id' => $company->id,
        ], $director);

        $this->assertNotNull($company->fresh()->last_activity_at);
    }

    public function test_creating_deal_activity_touches_company_and_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $deal = $this->dealFor($director, $pipeline);

        $linked = Contact::factory()->create(['owner_id' => $director->id]);
        $unlinked = Contact::factory()->create(['owner_id' => $director->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $linked->id]);

        $this->activities->create([
            'kind' => 'task',
            'title' => 'Follow up',
            'target_type' => 'deal',
            'target_id' => $deal->id,
        ], $director);

        $this->assertNotNull($deal->company->fresh()->last_activity_at, 'company touched');
        $this->assertNotNull($linked->fresh()->last_activity_at, 'linked contact touched');
        $this->assertNull($unlinked->fresh()->last_activity_at, 'unlinked contact NOT touched');
    }

    public function test_completing_deal_activity_touches_company_and_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $deal = $this->dealFor($director, $pipeline);
        $contact = Contact::factory()->create(['owner_id' => $director->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        // A pre-existing open task on the deal; complete it and assert the touch.
        $activity = Activity::factory()->task()->forDeal($deal)->responsibleOf($director)->create();

        // Reset the stamps the create-path would have set so we isolate complete().
        Company::query()->whereKey($deal->company_id)->update(['last_activity_at' => null]);
        Contact::query()->whereKey($contact->id)->update(['last_activity_at' => null]);

        $this->activities->complete($activity, $director);

        $this->assertNotNull($deal->company->fresh()->last_activity_at);
        $this->assertNotNull($contact->fresh()->last_activity_at);
    }

    public function test_creating_contact_activity_touches_contact_only(): void
    {
        $director = $this->director();
        $contact = $this->contactFor($director);
        $other = $this->contactFor($director);

        $this->assertNull($contact->last_activity_at);

        $this->activities->create([
            'kind' => 'call',
            'title' => 'Called the contact',
            'target_type' => 'contact',
            'target_id' => $contact->id,
        ], $director);

        $this->assertNotNull($contact->fresh()->last_activity_at, 'targeted contact touched');
        $this->assertNull($other->fresh()->last_activity_at, 'unrelated contact NOT touched');
    }

    public function test_creating_contact_activity_refreshes_engagement_tier(): void
    {
        $director = $this->director();
        // A long-stale last_activity_at => the contact starts off Cold.
        $contact = Contact::factory()->create([
            'owner_id' => $director->id,
            'last_activity_at' => now()->subDays(120),
        ]);

        $engagement = app(EngagementService::class);
        $this->assertSame(EngagementTier::Cold, $engagement->tierForContact($contact));

        $this->activities->create([
            'kind' => 'call',
            'title' => 'Reactivated the contact',
            'target_type' => 'contact',
            'target_id' => $contact->id,
        ], $director);

        // After a direct contact activity the stamp is now() => tier flips to Fresh.
        $this->assertSame(EngagementTier::Fresh, $engagement->tierForContact($contact->fresh()));
    }

    public function test_completing_contact_activity_touches_contact(): void
    {
        $director = $this->director();
        $contact = $this->contactFor($director);

        $activity = Activity::factory()->task()->forContact($contact)->responsibleOf($director)->create();

        // Isolate complete(): clear the stamp the factory-created row may carry.
        Contact::query()->whereKey($contact->id)->update(['last_activity_at' => null]);

        $this->activities->complete($activity, $director);

        $this->assertNotNull($contact->fresh()->last_activity_at);
    }

    public function test_standalone_activity_touches_nothing(): void
    {
        $director = $this->director();
        $company = $this->companyFor($director);

        // A personal task with no target must not stamp any Crm row.
        $this->activities->create([
            'kind' => 'task',
            'title' => 'Personal todo',
        ], $director);

        $this->assertNull($company->fresh()->last_activity_at);
    }

    // ---- Sales domain ----

    public function test_creating_deal_touches_company(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $company = $this->companyFor($director);

        $this->deals->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'title' => 'New deal',
            'currency' => 'RUB',
        ], $director);

        $this->assertNotNull($company->fresh()->last_activity_at);
    }

    public function test_updating_deal_touches_company_and_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $deal = $this->dealFor($director, $pipeline);
        $contact = Contact::factory()->create(['owner_id' => $director->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        // Clear the create-path stamp so we isolate the update() touch.
        Company::query()->whereKey($deal->company_id)->update(['last_activity_at' => null]);
        Contact::query()->whereKey($contact->id)->update(['last_activity_at' => null]);

        $this->deals->update($deal, ['title' => 'Renamed deal'], $director);

        $this->assertNotNull($deal->company->fresh()->last_activity_at);
        $this->assertNotNull($contact->fresh()->last_activity_at);
    }

    public function test_moving_deal_stage_touches_company_and_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $deal = $this->dealFor($director, $pipeline, 'new');
        $contact = Contact::factory()->create(['owner_id' => $director->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        Company::query()->whereKey($deal->company_id)->update(['last_activity_at' => null]);
        Contact::query()->whereKey($contact->id)->update(['last_activity_at' => null]);

        $target = $pipeline->stages
            ->where('is_won', false)
            ->where('is_lost', false)
            ->firstWhere('id', '!=', $deal->stage_id);

        $this->moves->move($deal, $target->id, $director->id);

        $this->assertNotNull($deal->company->fresh()->last_activity_at);
        $this->assertNotNull($contact->fresh()->last_activity_at);
    }

    public function test_noop_move_does_not_touch(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $deal = $this->dealFor($director, $pipeline, 'new');

        Company::query()->whereKey($deal->company_id)->update(['last_activity_at' => null]);

        // Moving to the SAME stage is a no-op; no engagement touch must fire.
        $this->moves->move($deal, (int) $deal->stage_id, $director->id);

        $this->assertNull($deal->company->fresh()->last_activity_at);
    }
}
