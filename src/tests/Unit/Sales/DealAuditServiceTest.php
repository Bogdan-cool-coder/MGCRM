<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Services\DealAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DealAuditService
    {
        return new DealAuditService;
    }

    public function test_record_creates_one_row_per_scalar_field(): void
    {
        $deal = Deal::factory()->create();
        $actor = User::factory()->create();

        $this->service()->record($deal, $actor, [
            'title' => ['old' => 'Old', 'new' => 'New'],
            'amount' => ['old' => 1000, 'new' => 2000],
            'currency' => ['old' => 'RUB', 'new' => 'USD'],
        ]);

        $this->assertDatabaseCount('deal_audits', 3);

        $title = DealAudit::query()->where('field', 'title')->firstOrFail();
        $this->assertSame('Old', $title->old_value);
        $this->assertSame('New', $title->new_value);
        $this->assertSame($actor->id, (int) $title->user_id);
        $this->assertSame($deal->id, (int) $title->deal_id);
    }

    public function test_record_with_null_actor_stores_null_user(): void
    {
        $deal = Deal::factory()->create();

        $this->service()->record($deal, null, [
            'title' => ['old' => 'A', 'new' => 'B'],
        ]);

        $row = DealAudit::query()->firstOrFail();
        $this->assertNull($row->user_id);
    }

    public function test_record_with_empty_changes_writes_nothing(): void
    {
        $deal = Deal::factory()->create();

        $this->service()->record($deal, null, []);

        $this->assertDatabaseCount('deal_audits', 0);
    }

    public function test_extra_fields_expanded_per_key(): void
    {
        $deal = Deal::factory()->create();

        $this->service()->record($deal, null, [
            'extra_fields' => [
                'old' => ['budget' => '100', 'crm_stage' => 'lead'],
                'new' => ['budget' => '200', 'crm_stage' => 'lead', 'source' => 'tg'],
            ],
        ]);

        // budget changed, source added; crm_stage unchanged → skipped.
        $this->assertDatabaseCount('deal_audits', 2);

        $budget = DealAudit::query()->where('field', 'extra_fields.budget')->firstOrFail();
        $this->assertSame('100', $budget->old_value);
        $this->assertSame('200', $budget->new_value);

        $source = DealAudit::query()->where('field', 'extra_fields.source')->firstOrFail();
        $this->assertNull($source->old_value);
        $this->assertSame('tg', $source->new_value);

        $this->assertDatabaseMissing('deal_audits', ['field' => 'extra_fields.crm_stage']);
    }

    public function test_for_deal_returns_rows_newest_first(): void
    {
        $deal = Deal::factory()->create();

        DealAudit::query()->insert([
            ['deal_id' => $deal->id, 'user_id' => null, 'field' => 'title', 'old_value' => 'A', 'new_value' => 'B', 'created_at' => now()->subDays(2)],
            ['deal_id' => $deal->id, 'user_id' => null, 'field' => 'amount', 'old_value' => '1', 'new_value' => '2', 'created_at' => now()->subDay()],
            ['deal_id' => $deal->id, 'user_id' => null, 'field' => 'currency', 'old_value' => 'RUB', 'new_value' => 'USD', 'created_at' => now()],
        ]);

        $page = $this->service()->forDeal($deal);

        $this->assertSame(3, $page->total());
        $fields = $page->getCollection()->pluck('field')->all();
        $this->assertSame(['currency', 'amount', 'title'], $fields);
    }

    public function test_for_deal_is_scoped_to_the_deal(): void
    {
        $deal = Deal::factory()->create();
        $other = Deal::factory()->create();

        $this->service()->record($deal, null, ['title' => ['old' => 'A', 'new' => 'B']]);
        $this->service()->record($other, null, ['title' => ['old' => 'C', 'new' => 'D']]);

        $page = $this->service()->forDeal($deal);

        $this->assertSame(1, $page->total());
        $this->assertSame('B', $page->getCollection()->first()->new_value);
    }
}
