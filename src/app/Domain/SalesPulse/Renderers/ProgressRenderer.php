<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Renderers;

use App\Domain\SalesPulse\Data\ProgressLine;
use Carbon\CarbonImmutable;

/**
 * ProgressRenderer — the /progress message (spec §6.1, 1 HTML message).
 *
 * Header: "📊 Рабочая активность {team} за {DD.MM.YYYY} {label}"
 *   label = "полдень" / "вечер" / "HH:MM" (caller-supplied — the scheduler passes
 *   the named slot, a manual call passes the wall-clock HH:MM).
 *
 * Per-manager line (spec §6.1):
 *   vacation → "{name} = 🌴 отпуск до {DD.MM}"
 *   skip     → "{name} = ⏸ скип"
 *   no-plan  → "{name_link} = плана нет (/startday не было)"
 *   zero     → "{name_link} = 0/0"
 *   live     → "{name_link} = {done}/{total}{suffix}"
 *              suffix (only when remaining = total − done > 0):
 *                " ({postponed} перенесено, {notes_count} с заметками, {in_progress} в работе)"
 *
 * Pure formatter — no DB. Vacation/skip use the plain {name} (no link), the rest
 * use {name_link} exactly as the bot did.
 */
class ProgressRenderer
{
    /**
     * @param  list<ProgressLine>  $lines
     */
    public function render(string $teamName, CarbonImmutable $date, string $label, array $lines): string
    {
        $dateLabel = $date->format('d.m.Y');

        $out = ["📊 Рабочая активность {$teamName} за {$dateLabel} {$label}"];

        foreach ($lines as $line) {
            $out[] = $this->renderLine($line);
        }

        return implode("\n", $out);
    }

    public function renderLine(ProgressLine $line): string
    {
        return match ($line->variant) {
            ProgressLine::VARIANT_VACATION => "{$line->name} = 🌴 отпуск до {$line->vacationUntil}",
            ProgressLine::VARIANT_SKIP => "{$line->name} = ⏸ скип",
            ProgressLine::VARIANT_NO_PLAN => "{$line->nameLink} = плана нет (/startday не было)",
            ProgressLine::VARIANT_ZERO => "{$line->nameLink} = 0/0",
            ProgressLine::VARIANT_LIVE => $this->renderLive($line),
            default => "{$line->nameLink} = 0/0",
        };
    }

    private function renderLive(ProgressLine $line): string
    {
        $suffix = '';
        $remaining = $line->total - $line->done;

        if ($remaining > 0) {
            $suffix = " ({$line->postponed} перенесено, {$line->notesCount} с заметками, {$line->inProgress} в работе)";
        }

        return "{$line->nameLink} = {$line->done}/{$line->total}{$suffix}";
    }
}
