<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Renderers;

use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseStageResolver;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Data\StageMeta;
use App\Domain\SalesPulse\Services\StageClassificationService;

/**
 * PlanRenderer — the /startday morning-plan message (spec §7, plain text).
 *
 * Layout (verbatim):
 *   📋 План на {ISO} — {name}
 *   {emoji} {i}. {company} — {stage}{ ♻️ N-й день} — {✓ }{text}
 *   ...
 *   Всего задач: {n}
 *
 * Empty plan → "Задач на сегодня нет." (no header). Rows are ordered by the AMO
 * `_sort_key` = (status_sort_key hot→cold, is_done last, company, task_id) so the
 * morning list reads hottest deal first, completed rows sinking within a group.
 *
 * The "♻️ N-й день" suffix appears when carryover_days >= 1 (the task has been on
 * the plan for prior days too); N = carryover_days + 1 (today is the N-th day).
 * A completed row is prefixed with "✓ " before its text.
 *
 * Pure formatter — no DB. The stage emoji/name are resolved through the injected
 * PulseStageResolver + StageMeta, so tests render against hand-built snapshots.
 */
class PlanRenderer
{
    public function __construct(
        private readonly StageClassificationService $classifier,
    ) {}

    public function render(DaySnapshot $snapshot, PulseStageResolver $stages): string
    {
        $rows = $snapshot->plan;

        if ($rows === []) {
            return 'Задач на сегодня нет.';
        }

        $sorted = $this->sortRows($rows, $stages);

        $lines = ["📋 План на {$snapshot->onDate} — {$snapshot->managerName}"];

        $i = 1;
        foreach ($sorted as $row) {
            $lines[] = $this->renderRow($i, $row, $stages);
            $i++;
        }

        $lines[] = 'Всего задач: '.count($rows);

        return implode("\n", $lines);
    }

    private function renderRow(int $i, PulseTaskRow $row, PulseStageResolver $stages): string
    {
        $stage = $stages->resolve($row->dealStageId);
        $meta = StageMeta::forStage($stage);
        $stageName = $stages->name($row->dealStageId, $row->dealStageName);

        $company = $row->dealTitle ?? '';

        $carryover = '';
        if ($row->carryoverDays >= 1) {
            // Today is the (carryover_days + 1)-th consecutive day on the plan.
            $day = $row->carryoverDays + 1;
            $carryover = " ♻️ {$day}-й день";
        }

        $check = $row->isCompleted ? '✓ ' : '';
        $text = $row->text;

        return "{$meta->emoji} {$i}. {$company} — {$stageName}{$carryover} — {$check}{$text}";
    }

    /**
     * Order rows by the AMO `_sort_key`: status_sort_key (hot→cold), then is_done
     * (open before completed), then company name, then task_id for stability.
     *
     * @param  list<PulseTaskRow>  $rows
     * @return list<PulseTaskRow>
     */
    private function sortRows(array $rows, PulseStageResolver $stages): array
    {
        usort($rows, function (PulseTaskRow $a, PulseTaskRow $b) use ($stages): int {
            $keyA = $this->classifier->statusSortKey($stages->resolve($a->dealStageId));
            $keyB = $this->classifier->statusSortKey($stages->resolve($b->dealStageId));

            return $keyA <=> $keyB
                ?: ($a->isCompleted <=> $b->isCompleted)
                ?: (($a->dealTitle ?? '') <=> ($b->dealTitle ?? ''))
                ?: ($a->taskId <=> $b->taskId);
        });

        return array_values($rows);
    }
}
