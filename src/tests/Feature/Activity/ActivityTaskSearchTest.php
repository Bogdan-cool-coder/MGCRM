<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\Activity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Case-insensitive task search audit (mirrors DealListFiltersTest / ContactSearchTest).
 *
 * The two task-search sites — applyListFilters (flat list + presets, ?q=) and
 * myBoard (?q= on the personal board) — were converted from a case-sensitive
 * plain `whereLike('title','like','%…%')` to `whereLikeCi`. PostgreSQL LIKE is
 * case-sensitive, so a Cyrillic «Звонок» typed as «звонок» (or vice versa) never
 * matched the stored value. whereLikeCi emits ILIKE on PG / LOWER()…LIKE on SQLite.
 *
 * NOTE on SQLite + Cyrillic (mirrors DealListFiltersTest): SQLite's LOWER() folds
 * only ASCII; Cyrillic case-folding needs the ICU extension the :memory: test DB
 * lacks. So the case-INSENSITIVE assertions use ASCII fixtures (the SQLite path)
 * and the Cyrillic fixtures use a SAME-case fragment to prove the search pipeline
 * is wired. The PostgreSQL ILIKE Cyrillic case-fold is a production-runtime
 * guarantee (the macro switches on driver name).
 */
class ActivityTaskSearchTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze at a deterministic operational mid-day so the board's urgency
        // bucketing (Asia/Dubai) is stable regardless of the suite's wall-clock.
        Carbon::setTestNow(Carbon::parse('2026-03-16 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<int> task ids returned by GET /api/activities with the given query */
    private function listIds(string $query): array
    {
        $response = $this->getJson('/api/activities'.$query)->assertOk();

        return array_map(static fn (array $row): int => (int) $row['id'], $response->json('data'));
    }

    /** @return list<int> task ids across every bucket of GET /api/activities/my-board */
    private function boardIds(string $query): array
    {
        $data = $this->getJson('/api/activities/my-board'.$query)->assertOk()->json('data');

        $ids = [];
        foreach ($data as $bucket) {
            foreach ($bucket as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    // ================================================ flat list / presets (?q=)

    public function test_list_q_matches_title_case_insensitively_ascii(): void
    {
        $manager = $this->manager();

        // Title-cased fixture; lowercase + uppercase queries must both match via
        // LOWER()/ILIKE. Under the old plain whereLike on PG these would have missed.
        $alpha = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Call Alpha Corp', 'body' => null]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Meeting Beta Ltd', 'body' => null]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertSame([$alpha->id], $this->listIds('?q=alpha'));
        $this->assertSame([$alpha->id], $this->listIds('?q=ALPHA'));
    }

    public function test_list_q_matches_body_case_insensitively_ascii(): void
    {
        $manager = $this->manager();

        $target = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Follow up', 'body' => 'Discuss Renewal terms']);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Follow up', 'body' => 'Send invoice']);

        Sanctum::actingAs($manager, ['*']);

        // Lowercase query matches the title-cased body word "Renewal".
        $this->assertSame([$target->id], $this->listIds('?q=renewal'));
    }

    public function test_list_q_matches_partial_cyrillic_same_case(): void
    {
        $manager = $this->manager();

        // Same-case Cyrillic fragment proves the search PIPELINE is wired (substring
        // match) independent of SQLite's missing Cyrillic case-fold.
        $target = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Подписать контракт', 'body' => null]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Встреча с клиентом', 'body' => null]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertSame([$target->id], $this->listIds('?q='.urlencode('контракт')));
    }

    public function test_list_q_intersects_with_status_filter_and_logic(): void
    {
        $manager = $this->manager();

        // Matches BOTH q=alpha AND status=done.
        $match = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create(['title' => 'Alpha review', 'body' => null]);
        // q matches but status wrong → excluded by AND.
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Alpha kickoff', 'body' => null]);
        // status matches but q does not → excluded by AND.
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create(['title' => 'Beta review', 'body' => null]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertSame([$match->id], $this->listIds('?q=alpha&status[]=done'));
    }

    public function test_list_empty_q_is_ignored(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        // A blank q must be a no-op, not collapse the result set.
        $this->assertCount(2, $this->listIds('?q='));
    }

    // ===================================================== personal board (?q=)

    public function test_board_q_matches_title_case_insensitively_ascii(): void
    {
        $manager = $this->manager();

        $alpha = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Call Alpha Corp', 'body' => null, 'due_at' => now()->setTime(12, 0)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Meeting Beta Ltd', 'body' => null, 'due_at' => now()->setTime(12, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertSame([$alpha->id], $this->boardIds('?q=alpha'));
        $this->assertSame([$alpha->id], $this->boardIds('?q=ALPHA'));
    }

    public function test_board_q_matches_body_and_partial_cyrillic_same_case(): void
    {
        $manager = $this->manager();

        $target = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Звонок', 'body' => 'Обсудить контракт', 'due_at' => now()->setTime(12, 0)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Встреча', 'body' => 'Демо продукта', 'due_at' => now()->setTime(12, 0)]);

        Sanctum::actingAs($manager, ['*']);

        // Matches the body fragment of the first task only.
        $this->assertSame([$target->id], $this->boardIds('?q='.urlencode('контракт')));
    }

    public function test_board_empty_q_is_ignored(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->assertCount(2, $this->boardIds('?q='));
    }
}
