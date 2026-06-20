<?php

declare(strict_types=1);

namespace Tests\Feature\Log;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class EntityLogEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function logs(): EntityLogService
    {
        return app(EntityLogService::class);
    }

    private function makeDeal(User $owner): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
    }

    // ---- Deal log ----

    public function test_deal_log_returns_rows_newest_first(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);

        $this->logs()->record(LogSubjectType::Deal, (int) $deal->id, $user, LogAction::Created, [], now()->subDays(2));
        $this->logs()->record(LogSubjectType::Deal, (int) $deal->id, $user, LogAction::StageChanged, [
            'from_stage_id' => 1, 'to_stage_id' => 2,
        ], now());

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/deals/{$deal->id}/log")->assertOk();

        $response->assertJsonPath('meta.total', 2);
        $actions = collect($response->json('data'))->pluck('action')->all();
        $this->assertSame(['stage_changed', 'created'], $actions);
        // Actor + meta are exposed on the resource.
        $response->assertJsonPath('data.0.actor.id', $user->id);
        $response->assertJsonPath('data.0.meta.to_stage_id', 2);
    }

    public function test_deal_log_paginates(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);

        for ($i = 0; $i < 5; $i++) {
            $this->logs()->record(
                LogSubjectType::Deal,
                (int) $deal->id,
                $user,
                LogAction::DataChanged,
                ['changes' => []],
                now()->subMinutes($i),
            );
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/deals/{$deal->id}/log?per_page=2")->assertOk();

        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonCount(2, 'data');
    }

    public function test_deal_log_requires_view_permission(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($owner);

        $this->logs()->record(LogSubjectType::Deal, (int) $deal->id, $owner, LogAction::Created);

        Sanctum::actingAs($other, ['*']);

        // A foreign deal under "own" scope is forbidden (mirrors deals/{deal}/feed).
        $this->getJson("/api/deals/{$deal->id}/log")->assertForbidden();
    }

    public function test_deal_log_visible_to_admin_all_scope(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->makeDeal($owner);

        $this->logs()->record(LogSubjectType::Deal, (int) $deal->id, $owner, LogAction::Created);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/deals/{$deal->id}/log")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ---- Company log ----

    public function test_company_log_returns_rows(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        $this->logs()->record(LogSubjectType::Company, (int) $company->id, $admin, LogAction::DataChanged, [
            'changes' => [['field' => 'name', 'old' => 'A', 'new' => 'B']],
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/companies/{$company->id}/log")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'data_changed')
            ->assertJsonPath('data.0.subject_type', 'company');
    }

    // ---- Contact log ----

    public function test_contact_log_returns_rows(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();

        $this->logs()->record(LogSubjectType::Contact, (int) $contact->id, $admin, LogAction::ContactAdded, [
            'contact_id' => $contact->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/contacts/{$contact->id}/log")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject_type', 'contact');
    }
}
