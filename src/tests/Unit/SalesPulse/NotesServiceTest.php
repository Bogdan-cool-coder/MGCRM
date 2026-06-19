<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\SalesPulse\Services\NotesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for note detection (spec §1.2 metric 3 input): deals that received a
 * `note` activity from the manager today (Asia/Dubai day window over created_at).
 */
class NotesServiceTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private NotesService $notes;

    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->notes = app(NotesService::class);
        $this->date = CarbonImmutable::parse('2026-06-19 12:00:00', 'Asia/Dubai');
    }

    private function dubai(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Dubai');
    }

    public function test_detects_deal_with_note_today(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($manager, $deal, kind: ActivityType::Note, createdAt: $this->dubai('2026-06-19 10:00:00'));

        $set = $this->notes->dealIdsWithNoteToday($manager, $this->date);

        $this->assertArrayHasKey((int) $deal->id, $set);
    }

    public function test_ignores_note_from_yesterday(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($manager, $deal, kind: ActivityType::Note, createdAt: $this->dubai('2026-06-18 23:00:00'));

        $set = $this->notes->dealIdsWithNoteToday($manager, $this->date);

        $this->assertArrayNotHasKey((int) $deal->id, $set);
    }

    public function test_ignores_note_from_another_manager(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        $this->makeActivity($other, $deal, kind: ActivityType::Note, createdAt: $this->dubai('2026-06-19 10:00:00'));

        $set = $this->notes->dealIdsWithNoteToday($manager, $this->date);

        $this->assertArrayNotHasKey((int) $deal->id, $set);
    }

    public function test_ignores_non_note_activity(): void
    {
        $manager = $this->makeManager();
        $deal = $this->makeDeal('qualify', $manager);
        // A call today is not a note → not in the set.
        $this->makeActivity($manager, $deal, kind: ActivityType::Call, dueAt: $this->dubai('2026-06-19 10:00:00'), createdAt: $this->dubai('2026-06-19 10:00:00'));

        $set = $this->notes->dealIdsWithNoteToday($manager, $this->date);

        $this->assertArrayNotHasKey((int) $deal->id, $set);
    }
}
