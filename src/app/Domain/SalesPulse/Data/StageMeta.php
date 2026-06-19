<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

use App\Domain\Sales\Models\PipelineStage;

/**
 * StageMeta — per-stage presentation + SLA metadata, keyed by PipelineStage.code.
 *
 * Port of the AMO bot's stage glyphs + SLA windows (spec §5.1 daily / §5.2 weekly /
 * §7 emoji). The lookup table lives in config('salespulse.stages') so a second
 * funnel (AI Global) extends it by adding rows; an unknown/null code resolves to
 * config('salespulse.stage_default'). This keeps the formatting layer (labels,
 * /dayresults SLA flags, /weeklyreport top_stuck thresholds) single-sourced.
 *
 * Pure value object — no DB access. The label glyph and thresholds are read from
 * config at resolve() time, so tests can stay DB-free by passing a code string.
 */
final readonly class StageMeta
{
    public function __construct(
        public string $emoji,
        public int $slaDays,
        public int $slaWeekly,
    ) {}

    /**
     * Resolve meta for a stage code. Null / unknown codes fall back to the
     * configured default so every caller is total.
     */
    public static function forCode(?string $code): self
    {
        $default = config('salespulse.stage_default');

        /** @var array<string, array{emoji: string, sla_days: int, sla_weekly: int}> $map */
        $map = config('salespulse.stages', []);

        $row = ($code !== null && isset($map[$code])) ? $map[$code] : $default;

        return new self(
            emoji: (string) $row['emoji'],
            slaDays: (int) $row['sla_days'],
            slaWeekly: (int) $row['sla_weekly'],
        );
    }

    /**
     * Resolve meta from a PipelineStage model (uses its code).
     */
    public static function forStage(?PipelineStage $stage): self
    {
        return self::forCode($stage?->code);
    }

    /**
     * The stage label as rendered in bot messages: "{emoji} {name}" (spec §7).
     */
    public function label(string $name): string
    {
        return trim($this->emoji.' '.$name);
    }

    /**
     * @return array{emoji: string, sla_days: int, sla_weekly: int}
     */
    public function toArray(): array
    {
        return [
            'emoji' => $this->emoji,
            'sla_days' => $this->slaDays,
            'sla_weekly' => $this->slaWeekly,
        ];
    }
}
