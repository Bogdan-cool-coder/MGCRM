<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\SalesPulse\Data\ProgressLine;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use App\Domain\SalesPulse\Renderers\ProgressRenderer;
use App\Domain\SalesPulse\Services\DayWindowResolver;
use App\Domain\SalesPulse\Services\ProgressService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProgressService + ProgressRenderer — the /progress live recompute (spec §6.1).
 * Covers each line variant (vacation/skip/no-plan/zero/live), the live counters
 * (done / postponed / in_progress / notes_count) recomputed from CURRENT activity
 * state, and the renderer's exact strings + suffix.
 */
class ProgressTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private ProgressService $service;

    private ProgressRenderer $renderer;

    private SnapshotRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->service = app(ProgressService::class);
        $this->renderer = app(ProgressRenderer::class);
        $this->repo = app(SnapshotRepository::class);
    }

    public function test_no_plan_variant(): void
    {
        $manager = $this->makeManager();
        $date = CarbonImmutable::parse('2026-06-19');

        $line = $this->service->lineFor($manager, $date, 'LINK');

        $this->assertSame(ProgressLine::VARIANT_NO_PLAN, $line->variant);
        $this->assertSame('LINK = плана нет (/startday не было)', $this->renderer->renderLine($line));
    }

    public function test_skip_variant(): void
    {
        $manager = $this->makeManager();
        $date = CarbonImmutable::parse('2026-06-19');

        PulseSkipDay::create([
            'on_date' => $date->toDateString(),
            'manager_id' => $manager->id,
            'created_by' => $manager->id,
        ]);

        $line = $this->service->lineFor($manager, $date, 'LINK');

        $this->assertSame(ProgressLine::VARIANT_SKIP, $line->variant);
        $this->assertStringContainsString('= ⏸ скип', $this->renderer->renderLine($line));
    }

    public function test_vacation_variant_wins_first(): void
    {
        $manager = $this->makeManager();
        $date = CarbonImmutable::parse('2026-06-19');

        $line = $this->service->lineFor($manager, $date, 'LINK', vacationUntil: '30.06');

        $this->assertSame(ProgressLine::VARIANT_VACATION, $line->variant);
        $this->assertStringContainsString('= 🌴 отпуск до 30.06', $this->renderer->renderLine($line));
    }

    public function test_zero_variant_for_empty_plan(): void
    {
        $manager = $this->makeManager();
        $date = CarbonImmutable::parse('2026-06-19');

        // Persist an empty PLAN snapshot.
        $this->repo->savePlan($this->snapshot(plan: [], managerId: (int) $manager->id), SnapSource::Manual);

        $line = $this->service->lineFor($manager, $date, 'LINK');

        $this->assertSame(ProgressLine::VARIANT_ZERO, $line->variant);
        $this->assertSame('LINK = 0/0', $this->renderer->renderLine($line));
    }

    public function test_live_variant_counts_done_postponed_in_progress_and_notes(): void
    {
        $manager = $this->makeManager();
        $date = CarbonImmutable::parse('2026-06-19');
        [, $to] = app(DayWindowResolver::class)->dayWindow($date);

        $dealDone = $this->makeDeal('warm', $manager);
        $dealOpen = $this->makeDeal('warm', $manager);
        $dealPostponed = $this->makeDeal('warm', $manager);

        // 3 plan tasks: one will be done, one open-in-window, one rescheduled forward.
        $aDone = $this->makeActivity($manager, $dealDone, dueAt: $date->setTime(10, 0));
        $aOpen = $this->makeActivity($manager, $dealOpen, dueAt: $date->setTime(11, 0));
        $aPostponed = $this->makeActivity($manager, $dealPostponed, dueAt: $date->setTime(12, 0));

        // Persist the morning PLAN from these task ids.
        $plan = $this->snapshot(
            plan: [
                $this->row((int) $aDone->id, (int) $dealDone->id, $dealDone->stage_id, dueAt: $date->setTime(10, 0)->toIso8601String()),
                $this->row((int) $aOpen->id, (int) $dealOpen->id, $dealOpen->stage_id, dueAt: $date->setTime(11, 0)->toIso8601String()),
                $this->row((int) $aPostponed->id, (int) $dealPostponed->id, $dealPostponed->stage_id, dueAt: $date->setTime(12, 0)->toIso8601String()),
            ],
            managerId: (int) $manager->id,
        );
        $this->repo->savePlan($plan, SnapSource::Manual);

        // Mutate live state: task 1 done, task 3 rescheduled past end of day.
        $aDone->update(['status' => ActivityStatus::Done->value, 'completed_at' => $date->setTime(13, 0), 'completed_by_id' => $manager->id]);
        $aPostponed->update(['due_at' => $to->addDay()]);

        // Open deal gets a note today → counts toward notes_count.
        $this->makeNote($manager, $dealOpen, $date);

        $line = $this->service->lineFor($manager, $date, 'LINK');

        $this->assertSame(ProgressLine::VARIANT_LIVE, $line->variant);
        $this->assertSame(3, $line->total);
        $this->assertSame(1, $line->done);
        $this->assertSame(1, $line->postponed);   // rescheduled forward
        $this->assertSame(1, $line->inProgress);  // 3 - 1 - 1
        $this->assertSame(1, $line->notesCount);  // open deal with a note

        $rendered = $this->renderer->renderLine($line);
        $this->assertSame('LINK = 1/3 (1 перенесено, 1 с заметками, 1 в работе)', $rendered);
    }

    public function test_renderer_header_and_label(): void
    {
        $date = CarbonImmutable::parse('2026-06-19');
        $line = ProgressLine::noPlan('Иван', 'LINK');

        $out = $this->renderer->render('MACRO Global', $date, 'полдень', [$line]);

        $this->assertStringContainsString('📊 Рабочая активность MACRO Global за 19.06.2026 полдень', $out);
        $this->assertStringContainsString('LINK = плана нет (/startday не было)', $out);
    }

    private function makeNote(User $manager, Deal $deal, CarbonImmutable $date): void
    {
        Activity::factory()->create([
            'responsible_id' => $manager->id,
            'kind' => ActivityType::Note->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'created_at' => $date->setTime(14, 0),
            'updated_at' => $date->setTime(14, 0),
        ]);
    }
}
