<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Renderers;

use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseMetrics;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use Carbon\CarbonImmutable;

/**
 * FactRenderer — the /finishday fact message (spec §7, HTML).
 *
 * Layout:
 *   📈 Факт за {DD.MM} — {name}
 *   [⚠️ warning when no morning plan was fixed]
 *
 *   ✅ Выполнено по плану (n)
 *   • {company} — {text}
 *   ...
 *   ❗ Не выполнено, но есть заметка (n)
 *   ...
 *   ❌ Не выполнено без заметок (n)
 *   ...
 *   🆕 Внеплановые (n)
 *   ...
 *
 *   {PulseMetrics::render()}
 *
 * Empty sections render "—". Row classification mirrors the §1.2 metrics at the
 * row level:
 *   - DONE_BY_PLAN : plan task completed in the evening snapshot.
 *   - NOT_DONE_NOTE: plan task not completed (or vanished) BUT its deal has a note today.
 *   - NOT_DONE_BARE: plan task not completed (or vanished) AND no note today.
 *   - EXTRA        : evening completed task whose id is NOT in the plan.
 *
 * When there is no morning plan, every completed evening task is "extra" and the
 * warning line tells the manager the whole fact is over-plan (spec §7).
 */
class FactRenderer
{
    /**
     * @param  DaySnapshot|null  $morningPlan  Morning PLAN (null = none was fixed).
     * @param  DaySnapshot  $eveningSnap  Fresh evening collect_day.
     * @param  array<int, true>  $dealIdsWithNotesToday  deal_id => true membership set.
     */
    public function render(
        ?DaySnapshot $morningPlan,
        DaySnapshot $eveningSnap,
        array $dealIdsWithNotesToday,
        PulseMetrics $metrics,
        ?CarbonImmutable $date = null,
    ): string {
        $dateLabel = $this->shortDate($eveningSnap->onDate, $date);
        $name = $eveningSnap->managerName;

        $lines = ["📈 Факт за {$dateLabel} — {$name}"];

        if ($morningPlan === null) {
            $lines[] = '';
            $lines[] = '⚠️ Утреннего плана не было — весь факт идёт сверх плана.';
        }

        $planRows = $morningPlan?->plan ?? [];
        $eveningByTaskId = $this->indexByTaskId($eveningSnap->plan);

        $doneByPlan = [];
        $notDoneNote = [];
        $notDoneBare = [];

        foreach ($planRows as $planRow) {
            $eveningRow = $eveningByTaskId[$planRow->taskId] ?? null;

            if ($eveningRow !== null && $eveningRow->isCompleted) {
                $doneByPlan[] = $planRow;

                continue;
            }

            $dealId = $planRow->dealId;
            $hasNote = $dealId !== null && isset($dealIdsWithNotesToday[$dealId]);

            if ($hasNote) {
                $notDoneNote[] = $planRow;
            } else {
                $notDoneBare[] = $planRow;
            }
        }

        // Extra = evening-completed tasks not in the plan (spec §1.2 metric 4).
        $planTaskIds = $this->taskIdSet($planRows);
        $extra = [];
        foreach ($eveningSnap->plan as $row) {
            if ($row->isCompleted && ! isset($planTaskIds[$row->taskId])) {
                $extra[] = $row;
            }
        }

        $lines[] = '';
        $lines[] = $this->section('✅ Выполнено по плану', $doneByPlan);
        $lines[] = '';
        $lines[] = $this->section('❗ Не выполнено, но есть заметка', $notDoneNote);
        $lines[] = '';
        $lines[] = $this->section('❌ Не выполнено без заметок', $notDoneBare);
        $lines[] = '';
        $lines[] = $this->section('🆕 Внеплановые', $extra);
        $lines[] = '';
        $lines[] = $metrics->render();

        return implode("\n", $lines);
    }

    /**
     * Render one titled section: "{title} (n)" then a "• {company} — {text}" line
     * per row, or "—" when the section is empty.
     *
     * @param  list<PulseTaskRow>  $rows
     */
    private function section(string $title, array $rows): string
    {
        $header = "{$title} ({$this->count($rows)})";

        if ($rows === []) {
            return "{$header}\n—";
        }

        $body = [];
        foreach ($rows as $row) {
            $company = $row->dealTitle ?? '';
            $body[] = "• {$company} — {$row->text}";
        }

        return $header."\n".implode("\n", $body);
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     */
    private function count(array $rows): int
    {
        return count($rows);
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, PulseTaskRow> task_id => row
     */
    private function indexByTaskId(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row->taskId] = $row;
        }

        return $map;
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, true>
     */
    private function taskIdSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $set[$row->taskId] = true;
        }

        return $set;
    }

    /**
     * "{DD.MM}" — from the explicit date when given, else parsed from on_date.
     */
    private function shortDate(string $onDate, ?CarbonImmutable $date): string
    {
        $carbon = $date ?? CarbonImmutable::parse($onDate);

        return $carbon->format('d.m');
    }
}
