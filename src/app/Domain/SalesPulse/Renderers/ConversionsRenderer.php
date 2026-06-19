<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Renderers;

use App\Domain\SalesPulse\Data\ConversionsData;
use App\Domain\SalesPulse\Support\RuPlural;

/**
 * ConversionsRenderer — the /conversions message (spec §6.2, admin, HTML).
 *
 * Sections:
 *   📊 <b>Конверсия по этапам</b> ({period})
 *   {from_label} → {to_label}: {passed}/{touched} = {pct}%{ ← узкое место}
 *   ...
 *   📊 <b>Сквозная воронка:</b> {n} сделок → {success} success = {pct}%
 *   ☠️ <b>Потери (lost):</b> {label} — {n}, ...
 *   🔵 <b>Заморозка (cold):</b> {label} — {n}, ...
 *   ⏱ <b>Скорость по этапам:</b>
 *   {label}: {avg} дн.{ ← залипают}
 *
 * The "← узкое место" marker is appended to the bottleneck gate (the gate with the
 * minimum pct). The "← залипают" marker is appended to slow stages (avg >= 3).
 * Pure formatter — no DB.
 */
class ConversionsRenderer
{
    public function render(ConversionsData $data): string
    {
        $lines = ["📊 <b>Конверсия по этапам</b> ({$data->periodLabel})"];

        foreach ($data->gates as $i => $gate) {
            $marker = ($data->bottleneckGateIndex === $i) ? ' ← узкое место' : '';
            $lines[] = "{$gate['from_label']} → {$gate['to_label']}: "
                ."{$gate['passed']}/{$gate['touched']} = {$gate['pct']}%{$marker}";
        }

        $lines[] = '';
        $f = $data->funnel;
        $lines[] = "📊 <b>Сквозная воронка:</b> {$f['in_funnel']} сделок → {$f['success']} success = {$f['overall_pct']}%";

        if ($data->lostByStage !== []) {
            $lines[] = '';
            $lines[] = '☠️ <b>Потери (lost):</b>';
            foreach ($data->lostByStage as $loss) {
                $lines[] = "  {$loss['label']} — {$loss['count']}";
            }
        }

        if ($data->coldByStage !== []) {
            $lines[] = '';
            $lines[] = '🔵 <b>Заморозка (cold):</b>';
            foreach ($data->coldByStage as $loss) {
                $lines[] = "  {$loss['label']} — {$loss['count']}";
            }
        }

        if ($data->velocity !== []) {
            $lines[] = '';
            $lines[] = '⏱ <b>Скорость по этапам:</b>';
            foreach ($data->velocity as $v) {
                $slow = $v['slow'] ? ' ← залипают' : '';
                $avg = $this->formatAvg((float) $v['avg_days']);
                $lines[] = "  {$v['label']}: {$avg} ".RuPlural::pick((int) round((float) $v['avg_days']), 'день', 'дня', 'дней').$slow;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Trim a trailing ".0" so whole averages read "3" not "3.0".
     */
    private function formatAvg(float $avg): string
    {
        if (floor($avg) === $avg) {
            return (string) (int) $avg;
        }

        return rtrim(rtrim(number_format($avg, 1, '.', ''), '0'), '.');
    }
}
