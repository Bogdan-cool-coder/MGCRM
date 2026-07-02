<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Services\DealFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DealFeedService
    {
        return new DealFeedService;
    }

    /**
     * A REAL stage transition (from_stage_id is non-null). The feed deliberately
     * EXCLUDES the genesis row (from_stage_id = null) so the deal's creation isn't
     * rendered twice (#4); see test_creation_stage_row_is_excluded_from_feed and
     * stageGenesis() for that case. Helpers used by every other test create a real
     * move so they exercise a stage_change the feed is supposed to surface.
     */
    private function stage(Deal $deal, ?string $at = null): DealStageHistory
    {
        return DealStageHistory::query()->create([
            'deal_id' => $deal->id,
            'from_stage_id' => $deal->stage_id, // non-null: a genuine transition
            'to_stage_id' => $deal->stage_id,
            'user_id' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * The genesis stage row written when a deal is first created: from_stage_id is
     * null. It must NEVER appear in the feed (#4) — its presence would duplicate
     * the deal-creation already represented by the other sources.
     */
    private function stageGenesis(Deal $deal, ?string $at = null): DealStageHistory
    {
        return DealStageHistory::query()->create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $deal->stage_id,
            'user_id' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    private function audit(Deal $deal, ?string $at = null, string $field = 'title', ?string $old = 'A', ?string $new = 'B'): void
    {
        DealAudit::query()->insert([
            'deal_id' => $deal->id,
            'user_id' => null,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'created_at' => $at ?? now(),
        ]);
    }

    private function paymentFixed(Deal $deal, ?string $at = null, ?User $actor = null): EntityLog
    {
        return EntityLog::query()->create([
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $deal->id,
            'actor_id' => $actor?->id,
            'action' => LogAction::PaymentFixed->value,
            'meta' => ['amount' => 1_500_00, 'currency' => 'RUB', 'paid_at' => '2026-06-20'],
            'created_at' => $at ?? now(),
        ]);
    }

    public function test_normalises_each_source_into_uniform_shape(): void
    {
        $deal = Deal::factory()->create();
        $actor = User::factory()->create(['full_name' => 'Jane Doe']);

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->createdByUser($actor)->create(['title' => 'Call client']);
        $this->audit($deal);

        $result = $this->service()->feed($deal);

        $this->assertCount(3, $result['data']);

        foreach ($result['data'] as $event) {
            $this->assertArrayHasKey('id', $event);
            $this->assertArrayHasKey('type', $event);
            $this->assertArrayHasKey('occurred_at', $event);
            $this->assertArrayHasKey('actor', $event);
            $this->assertArrayHasKey('payload', $event);
        }

        $types = array_column($result['data'], 'type');
        sort($types);
        $this->assertSame(['activity', 'field_change', 'stage_change'], $types);
    }

    public function test_composite_ids_are_prefixed_per_source(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->create();
        $this->audit($deal);

        $ids = array_column($this->service()->feed($deal)['data'], 'id');

        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'stage_')));
        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'activity_')));
        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'audit_')));
    }

    public function test_events_are_sorted_by_occurred_at_descending(): void
    {
        $deal = Deal::factory()->create();

        // Oldest → newest across all three sources, intentionally interleaved.
        $this->audit($deal, (string) now()->subDays(3));
        Activity::factory()->forDeal($deal)->create(['created_at' => now()->subDay()]);
        $this->stage($deal, (string) now()->subDays(2));
        Activity::factory()->forDeal($deal)->create(['created_at' => now()]);

        $events = $this->service()->feed($deal)['data'];

        $occurred = array_column($events, 'occurred_at');
        $sorted = $occurred;
        rsort($sorted);

        $this->assertSame($sorted, $occurred);
    }

    public function test_filter_by_type_returns_only_requested_sources(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->create();
        $this->audit($deal);

        $result = $this->service()->feed($deal, ['types' => ['stage_change']]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('stage_change', $result['data'][0]['type']);
    }

    public function test_invalid_type_filter_is_ignored(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        $this->audit($deal);

        // Only an unknown type → treated as "no filter" → all events returned.
        $result = $this->service()->feed($deal, ['types' => ['nonsense']]);

        $this->assertSame(2, $result['meta']['total']);
    }

    public function test_pagination_slices_via_for_page_and_reports_total(): void
    {
        $deal = Deal::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->audit($deal, (string) now()->subMinutes($i));
        }

        $page1 = $this->service()->feed($deal, [], 1, 2);
        $page2 = $this->service()->feed($deal, [], 2, 2);
        $page3 = $this->service()->feed($deal, [], 3, 2);

        $this->assertSame(5, $page1['meta']['total']);
        $this->assertSame(2, $page1['meta']['per_page']);
        $this->assertSame(1, $page1['meta']['current_page']);

        $this->assertCount(2, $page1['data']);
        $this->assertCount(2, $page2['data']);
        $this->assertCount(1, $page3['data']);

        // No id appears on more than one page.
        $allIds = array_merge(
            array_column($page1['data'], 'id'),
            array_column($page2['data'], 'id'),
            array_column($page3['data'], 'id'),
        );
        $this->assertCount(5, array_unique($allIds));
    }

    public function test_feed_is_scoped_to_the_deal(): void
    {
        $deal = Deal::factory()->create();
        $other = Deal::factory()->create();

        $this->audit($deal);
        $this->audit($other);
        Activity::factory()->forDeal($other)->create();

        $result = $this->service()->feed($deal);

        $this->assertSame(1, $result['meta']['total']);
    }

    // ---- #4: the genesis stage row (from_stage_id = null) is excluded ----

    public function test_creation_stage_row_is_excluded_from_feed(): void
    {
        $deal = Deal::factory()->create();

        // Only the genesis row exists — it must NOT surface as a stage_change, so
        // the deal's creation is not rendered twice in the timeline (#4).
        $this->stageGenesis($deal);

        $result = $this->service()->feed($deal);

        $this->assertSame(0, $result['meta']['total'], 'the genesis stage row must not appear in the feed');
        $this->assertCount(0, $result['data']);
    }

    public function test_newly_created_deal_has_exactly_one_creation_entry(): void
    {
        $deal = Deal::factory()->create();

        // A real "deal created" representation: the genesis stage row PLUS the
        // first real move. The genesis is dropped, so only the genuine transition
        // remains — exactly one creation/first entry, never a duplicate.
        $this->stageGenesis($deal, (string) now()->subMinute());
        $move = $this->stage($deal, (string) now());

        $result = $this->service()->feed($deal);

        $stageEvents = collect($result['data'])->where('type', 'stage_change');
        $this->assertCount(1, $stageEvents, 'only the real transition appears, not the genesis duplicate');
        $this->assertSame("stage_{$move->id}", $stageEvents->first()['id']);
    }

    // ---- payment_fixed: entity_logs surfaced as a feed source ----

    public function test_payment_fixed_log_appears_in_the_feed(): void
    {
        $deal = Deal::factory()->create();
        $actor = User::factory()->create(['full_name' => 'Acc Ountant']);

        $log = $this->paymentFixed($deal, null, $actor);

        $result = $this->service()->feed($deal);

        $payment = collect($result['data'])->firstWhere('type', 'payment_fixed');

        $this->assertNotNull($payment, 'a payment_fixed event must be present in the feed');
        $this->assertSame("payment_{$log->id}", $payment['id']);
        $this->assertSame(['id' => $actor->id, 'full_name' => 'Acc Ountant'], $payment['actor']);
        $this->assertSame(1_500_00, $payment['payload']['amount']);
        $this->assertSame('RUB', $payment['payload']['currency']);
        $this->assertSame('2026-06-20', $payment['payload']['paid_at']);
    }

    public function test_payment_fixed_is_not_duplicated_and_other_log_actions_excluded(): void
    {
        $deal = Deal::factory()->create();

        // One payment_fixed → must appear EXACTLY once.
        $this->paymentFixed($deal);

        // Other log verbs share the entity_logs table but are already represented
        // by stage/activity rows — they must NOT be pulled into the feed.
        EntityLog::factory()->forDeal($deal)->action(LogAction::Created)->create();
        EntityLog::factory()->forDeal($deal)->action(LogAction::TaskCompleted)->create();
        EntityLog::factory()->forDeal($deal)->action(LogAction::MeetingHeld)->create();
        EntityLog::factory()->forDeal($deal)->action(LogAction::StageChanged)->create();

        $result = $this->service()->feed($deal);

        $payments = collect($result['data'])->where('type', 'payment_fixed');

        $this->assertCount(1, $payments, 'payment_fixed must appear once, never duplicated');
        $this->assertSame(1, $result['meta']['total'], 'only the payment_fixed log contributes to total');
    }

    public function test_payment_fixed_filter_returns_only_that_source(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->create();
        $this->audit($deal);
        $this->paymentFixed($deal);

        $result = $this->service()->feed($deal, ['types' => ['payment_fixed']]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('payment_fixed', $result['data'][0]['type']);
    }

    public function test_payment_fixed_is_ordered_with_other_sources_by_date(): void
    {
        $deal = Deal::factory()->create();

        $this->audit($deal, (string) now()->subDays(3));
        $this->paymentFixed($deal, (string) now()->subDays(2));
        Activity::factory()->forDeal($deal)->create(['created_at' => now()->subDay()]);
        $this->stage($deal, (string) now());

        $events = $this->service()->feed($deal)['data'];

        $occurred = array_column($events, 'occurred_at');
        $sorted = $occurred;
        rsort($sorted);

        $this->assertSame($sorted, $occurred);
        // The payment event sits in the middle of the timeline, not first/last.
        $this->assertSame('payment_fixed', $events[2]['type']);
    }

    public function test_payment_fixed_contributes_to_total_and_pagination(): void
    {
        $deal = Deal::factory()->create();

        // 3 audits + 2 payments interleaved on distinct minutes.
        $this->audit($deal, (string) now()->subMinutes(5));
        $this->paymentFixed($deal, (string) now()->subMinutes(4));
        $this->audit($deal, (string) now()->subMinutes(3));
        $this->paymentFixed($deal, (string) now()->subMinutes(2));
        $this->audit($deal, (string) now()->subMinutes(1));

        $page1 = $this->service()->feed($deal, [], 1, 2);
        $page2 = $this->service()->feed($deal, [], 2, 2);
        $page3 = $this->service()->feed($deal, [], 3, 2);

        $this->assertSame(5, $page1['meta']['total'], 'total spans audits + payments');
        $this->assertCount(2, $page1['data']);
        $this->assertCount(2, $page2['data']);
        $this->assertCount(1, $page3['data']);

        $allIds = array_merge(
            array_column($page1['data'], 'id'),
            array_column($page2['data'], 'id'),
            array_column($page3['data'], 'id'),
        );
        $this->assertCount(5, array_unique($allIds), 'no id appears on more than one page');
    }

    // ---- F27: bounded-fetch perf optimisation must be byte-identical ----

    /**
     * Seeds N events of EACH source with strictly descending, collision-free
     * timestamps, so the global chronological order across all three sources is
     * fully deterministic (no created_at ties between rows).
     *
     * @return Deal the deal carrying the seeded feed
     */
    private function seedInterleavedFeed(Deal $deal, int $perSource): Deal
    {
        $base = now()->subYear();

        for ($i = 0; $i < $perSource; $i++) {
            // Spread the three sources across distinct minute offsets so no two
            // rows share a timestamp — the same property that lets the bounded
            // fetch reproduce the page exactly.
            $this->stage($deal, (string) $base->copy()->addMinutes($i * 3));
            Activity::factory()->forDeal($deal)->create([
                'created_at' => $base->copy()->addMinutes($i * 3 + 1),
            ]);
            $this->audit($deal, (string) $base->copy()->addMinutes($i * 3 + 2));
        }

        return $deal;
    }

    /**
     * Golden reference: reproduces the PRE-optimisation behaviour — load the
     * newest min(count, 500) of each source, merge, sort desc, forPage — with
     * NO bounded fetch. The optimised service must return identical data/meta.
     *
     * @return array{ids: array<int, string>, total: int}
     */
    private function referencePage(Deal $deal, int $page, int $perPage): array
    {
        $cap = 500;

        $stage = DealStageHistory::query()->where('deal_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (DealStageHistory $r): array => [
                'id' => "stage_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);
        $activity = Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (Activity $r): array => [
                'id' => "activity_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);
        $audit = DealAudit::query()->where('deal_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (DealAudit $r): array => [
                'id' => "audit_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);

        $merged = $stage->merge($activity)->merge($audit)
            ->sortByDesc(fn (array $e): string => $e['at'] ?? '')
            ->values();

        return [
            'ids' => $merged->forPage($page, $perPage)->pluck('id')->values()->all(),
            'total' => $merged->count(),
        ];
    }

    public function test_bounded_fetch_is_identical_to_full_merge_on_first_pages(): void
    {
        // 40 of each source = 120 events, well over the 30-row page so the
        // bounded fetch (offset+perPage) genuinely loads fewer than the full set.
        $deal = $this->seedInterleavedFeed(Deal::factory()->create(), 40);

        foreach ([1, 2, 3] as $page) {
            $ref = $this->referencePage($deal, $page, 30);
            $actual = $this->service()->feed($deal, [], $page, 30);

            $this->assertSame(
                $ref['ids'],
                array_column($actual['data'], 'id'),
                "page {$page}: ids/order must match the full-merge reference",
            );
            $this->assertSame(120, $actual['meta']['total'], "page {$page}: total parity");
            $this->assertSame($ref['total'], $actual['meta']['total'], "page {$page}: total vs reference");
        }
    }

    public function test_bounded_fetch_preserves_status_and_every_field(): void
    {
        $deal = $this->seedInterleavedFeed(Deal::factory()->create(), 40);

        $first = $this->service()->feed($deal, [], 1, 30)['data'][0];

        // Full shape contract (incl. the Phase-1 `status` on activity payloads).
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('occurred_at', $first);
        $this->assertArrayHasKey('actor', $first);
        $this->assertArrayHasKey('payload', $first);

        $activity = collect($this->service()->feed($deal, [], 1, 90)['data'])
            ->firstWhere('type', 'activity');
        $this->assertNotNull($activity);
        $this->assertArrayHasKey('status', $activity['payload']);
        $this->assertArrayHasKey('is_closed', $activity['payload']);
        $this->assertArrayHasKey('kind', $activity['payload']);
    }

    // ---- D3: a completed task surfaces in the feed at completion time ----

    public function test_completed_activity_uses_completed_at_as_occurred_at(): void
    {
        $deal = Deal::factory()->create();

        // A task created long ago but completed just now: its feed timeline position
        // must be the COMPLETION instant, not the (old) creation instant — otherwise
        // it re-sorts back out of view and looks like the completion wasn't recorded.
        $createdAt = now()->subDays(10);
        $completedAt = now();

        $activity = Activity::factory()->forDeal($deal)->completed()->create([
            'created_at' => $createdAt,
            'completed_at' => $completedAt,
        ]);

        $events = $this->service()->feed($deal)['data'];

        $item = collect($events)->firstWhere('id', "activity_{$activity->id}");

        $this->assertNotNull($item);
        $this->assertSame(
            $completedAt->toIso8601String(),
            $item['occurred_at'],
            'a completed task must take its completed_at as the feed occurred_at',
        );
        $this->assertNotSame(
            $createdAt->toIso8601String(),
            $item['occurred_at'],
            'a completed task must NOT keep its old created_at as the feed position',
        );
    }

    public function test_completed_activity_sorts_after_older_items_and_is_not_duplicated(): void
    {
        $deal = Deal::factory()->create();

        // An audit 1h ago, plus a task created 10 days ago but completed NOW. With
        // occurred_at = completed_at, the just-completed task must sort to the TOP
        // (newest), ahead of the 1h-old audit — appearing where the user looks.
        $this->audit($deal, (string) now()->subHour());

        $activity = Activity::factory()->forDeal($deal)->completed()->create([
            'created_at' => now()->subDays(10),
            'completed_at' => now(),
        ]);

        $events = $this->service()->feed($deal)['data'];

        // The completed activity is the newest event in the merge.
        $this->assertSame("activity_{$activity->id}", $events[0]['id']);
        $this->assertSame('activity', $events[0]['type']);

        // Exactly one row for the activity — no separate task_completed twin.
        $activityRows = collect($events)->where('id', "activity_{$activity->id}");
        $this->assertCount(1, $activityRows, 'a completed task must appear once, never duplicated');
    }

    public function test_open_activity_still_uses_created_at_as_occurred_at(): void
    {
        $deal = Deal::factory()->create();

        $createdAt = now()->subDays(3);

        // Open task (factory default: status new, is_closed false, no completed_at).
        $activity = Activity::factory()->forDeal($deal)->create(['created_at' => $createdAt]);

        $item = collect($this->service()->feed($deal)['data'])
            ->firstWhere('id', "activity_{$activity->id}");

        $this->assertNotNull($item);
        $this->assertSame(
            $createdAt->toIso8601String(),
            $item['occurred_at'],
            'an open task keeps created_at as its feed occurred_at',
        );
    }

    public function test_rejected_activity_without_completed_at_falls_back_to_created_at(): void
    {
        $deal = Deal::factory()->create();

        $createdAt = now()->subDays(2);

        // Rejected: is_closed = true, but no completed_at (rejection stamps no
        // completion). The fallback keeps it on created_at, identical to the PHP
        // and SQL branches.
        $activity = Activity::factory()->forDeal($deal)->create([
            'created_at' => $createdAt,
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => true,
            'completed_at' => null,
        ]);

        $item = collect($this->service()->feed($deal)['data'])
            ->firstWhere('id', "activity_{$activity->id}");

        $this->assertNotNull($item);
        $this->assertSame($createdAt->toIso8601String(), $item['occurred_at']);
    }

    public function test_deep_page_truncates_at_the_500_ceiling_like_before(): void
    {
        // 600 audit rows: only the newest 500 survive the per-source ceiling,
        // identical to the previous flat-500 behaviour. total must clamp to 500
        // and a page beyond index 500 must be empty.
        $deal = Deal::factory()->create();

        $base = now()->subYear();
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [
                'deal_id' => $deal->id,
                'user_id' => null,
                'field' => 'title',
                'old_value' => 'A',
                'new_value' => 'B',
                'created_at' => $base->copy()->addMinutes($i),
            ];
        }
        DealAudit::query()->insert($rows);

        // total is clamped to the ceiling (sum of min(count, 500)).
        $this->assertSame(500, $this->service()->feed($deal, [], 1, 30)['meta']['total']);

        // The 500 newest survive: page covering indices 480..510 returns exactly
        // the 20 rows up to the ceiling, none beyond it.
        $tail = $this->service()->feed($deal, [], 17, 30); // offset 480
        $this->assertCount(20, $tail['data']);

        // A page entirely past the ceiling is empty — deep-pagination truncation.
        $beyond = $this->service()->feed($deal, [], 18, 30); // offset 510 > 500
        $this->assertCount(0, $beyond['data']);
    }

    // ---- field_label: raw column names render as human-readable RU labels ----

    /**
     * Pull the single field_change payload for a deal that carries exactly one
     * audit row.
     *
     * @return array<string, mixed>
     */
    private function soleFieldChangePayload(Deal $deal): array
    {
        $event = collect($this->service()->feed($deal)['data'])
            ->firstWhere('type', DealFeedService::TYPE_FIELD_CHANGE);

        $this->assertNotNull($event, 'a field_change event must be present');

        return $event['payload'];
    }

    public function test_field_change_carries_human_readable_label(): void
    {
        $deal = Deal::factory()->create();

        // The bug: the feed rendered the raw column «discount_percent». It must
        // now carry a localized «Скидка» label while keeping the raw field too.
        $this->audit($deal, null, 'discount_percent', '0', '10');

        $payload = $this->soleFieldChangePayload($deal);

        $this->assertSame('discount_percent', $payload['field'], 'raw field kept for compatibility');
        $this->assertSame('Скидка', $payload['field_label']);
        $this->assertSame('10', $payload['new_value']);
    }

    public function test_core_deal_fields_resolve_to_ru_labels(): void
    {
        $deal = Deal::factory()->create();

        $expected = [
            'title' => 'Название',
            'amount' => 'Сумма',
            'currency' => 'Валюта',
            'owner_user_id' => 'Ответственный',
            'company_id' => 'Компания',
            'expected_close_date' => 'Планируемая дата закрытия',
            'tags' => 'Теги',
            'perpetual_license' => 'Бессрочная лицензия',
        ];

        foreach ($expected as $field => $label) {
            $one = Deal::factory()->create();
            $this->audit($one, null, $field);

            $this->assertSame(
                $label,
                $this->soleFieldChangePayload($one)['field_label'],
                "field {$field} must resolve to «{$label}»",
            );
        }
    }

    public function test_known_custom_field_resolves_to_its_def_label(): void
    {
        $deal = Deal::factory()->create();

        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'contract_number',
            'label' => 'Номер договора',
            'field_type' => 'text',
            'is_active' => true,
        ]);

        $this->audit($deal, null, 'extra_fields.contract_number', null, 'X-1');

        $this->assertSame('Номер договора', $this->soleFieldChangePayload($deal)['field_label']);
    }

    public function test_unknown_custom_field_falls_back_to_humanized_label_without_crashing(): void
    {
        $deal = Deal::factory()->create();

        // No CustomFieldDef exists for foo_bar → humanized fallback, never a crash
        // and never the raw «extra_fields.foo_bar» column string.
        $this->audit($deal, null, 'extra_fields.foo_bar', null, 'v');

        $label = $this->soleFieldChangePayload($deal)['field_label'];

        $this->assertSame('Foo bar', $label);
        $this->assertStringNotContainsString('extra_fields', $label);
    }

    public function test_unknown_amo_custom_field_drops_prefix_in_fallback(): void
    {
        $deal = Deal::factory()->create();

        // An AMO-migrated code with no def → the amo_cf_ prefix is stripped so the
        // fallback reads «709732», not «Amo cf 709732».
        $this->audit($deal, null, 'extra_fields.amo_cf_709732', null, 'v');

        $this->assertSame('709732', $this->soleFieldChangePayload($deal)['field_label']);
    }

    public function test_unknown_core_field_falls_back_to_humanized_label(): void
    {
        $deal = Deal::factory()->create();

        // A raw column with no map entry must never surface as snake_case.
        $this->audit($deal, null, 'some_new_column', 'a', 'b');

        $this->assertSame('Some new column', $this->soleFieldChangePayload($deal)['field_label']);
    }
}
