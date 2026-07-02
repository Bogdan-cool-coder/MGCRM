/**
 * KPI score-percentage display formatting.
 *
 * score_pct (fact / plan * 100) is intentionally uncapped on the backend so the
 * raw value stays available for ranking and tooltips. For DISPLAY, an extreme
 * outlier (e.g. one member with a giant won deal against a small plan → 15072%)
 * makes the badge and table visually meaningless, so the rendered value is
 * clamped to "{cap}%+" above a threshold while the caller may still surface the
 * exact figure in a title/tooltip.
 */
const DEFAULT_SCORE_CAP = 300

/**
 * Format a KPI score percentage for display.
 * Returns '—' (em-dash) for null (no salary plan set).
 * Clamps extreme values at `cap`%+ to keep badges visually meaningful.
 */
export function formatScorePct(pct: number | null, cap: number = DEFAULT_SCORE_CAP): string {
  if (pct === null) {
    return '—'
  }

  if (pct > cap) {
    return `${cap}%+`
  }

  return `${pct}%`
}
