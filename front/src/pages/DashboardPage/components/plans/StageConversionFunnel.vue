<template>
  <div class="stage-funnel">
    <template v-for="(row, i) in rows" :key="`${row.from_stage_id}-${row.to_stage_id}`">
      <div class="stage-funnel__step">
        <div class="stage-funnel__head">
          <span class="stage-funnel__name">{{ row.name }}</span>
          <div class="stage-funnel__metrics">
            <span class="stage-funnel__counts">{{ row.numCount }} / {{ row.denCount }}</span>
            <PctTag :value="row.pct" size="sm" />
          </div>
        </div>
        <!-- Conversion bar: filled to the conversion % (clamped 0..100). -->
        <div class="stage-funnel__bar" role="presentation">
          <div
            class="stage-funnel__bar-fill"
            :class="`stage-funnel__bar-fill--${row.badge}`"
            :style="{ width: `${row.fillPct}%` }"
          />
        </div>
      </div>

      <!-- Flow arrow between transitions -->
      <div v-if="i < rows.length - 1" class="stage-funnel__arrow" aria-hidden="true">
        <i class="pi pi-arrow-down" />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import PctTag from '@/components/shared/PctTag.vue'
import { pctToBadge } from '@/utils/motivation'
import type { PctBadge } from '@/entities/motivation'
import type { StageConversion } from '@/entities/reports'

const props = defineProps<{
  stages: StageConversion[]
}>()

interface FunnelRow {
  from_stage_id: number
  to_stage_id: number
  name: string
  numCount: number
  denCount: number
  pct: number | null
  fillPct: number
  badge: PctBadge
}

/**
 * One headline conversion per transition, read straight off the period-aggregate
 * row (num_count / den_count / fact_pct) — the R5 stage block has no per-month
 * `cells`; these first-entry counts ARE the honest chain semantics for the period.
 * Defensive: skip malformed rows so a bad shape degrades to an empty funnel, not
 * a render crash (the section wrapper also guards on shape).
 */
const rows = computed<FunnelRow[]>(() =>
  (props.stages ?? [])
    .filter(
      (s): s is StageConversion =>
        s != null && typeof s.num_count === 'number' && typeof s.den_count === 'number',
    )
    .map((s) => {
      const numCount = s.num_count
      const denCount = s.den_count
      // Prefer the backend fact_pct; fall back to a local ratio if absent.
      const pct =
        s.fact_pct != null
          ? Math.round(s.fact_pct)
          : denCount > 0
            ? Math.round((numCount / denCount) * 100)
            : null
      return {
        from_stage_id: s.from_stage_id,
        to_stage_id: s.to_stage_id,
        name: s.name,
        numCount,
        denCount,
        pct,
        fillPct: pct == null ? 0 : Math.min(100, Math.max(0, pct)),
        badge: pctToBadge(pct),
      }
    }),
)
</script>

<style lang="scss" scoped>
.stage-funnel {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.stage-funnel__step {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-3 $space-4;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  background: $surface-card;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.stage-funnel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
}

.stage-funnel__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.stage-funnel__metrics {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.stage-funnel__counts {
  font-size: $font-size-xs;
  color: $surface-500;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.stage-funnel__bar {
  height: 8px;
  width: 100%;
  border-radius: $radius-sm;
  background: $surface-200;
  overflow: hidden;

  .app-dark & {
    background: var(--p-surface-200);
  }
}

.stage-funnel__bar-fill {
  height: 100%;
  border-radius: $radius-sm;
  transition: width 0.3s ease;
  background: var(--p-primary-color);

  // Severity-tinted fill (matches the PctTag thresholds). Theme-reactive --p-*
  // tokens resolve per theme, so no dark override is needed.
  &--success {
    background: var(--p-green-500);
  }

  &--warning {
    background: var(--p-yellow-500);
  }

  &--danger {
    background: var(--p-red-500);
  }

  &--none {
    background: $surface-300;
  }
}

.stage-funnel__arrow {
  display: flex;
  justify-content: center;
  color: $surface-400;
  font-size: $font-size-sm;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
