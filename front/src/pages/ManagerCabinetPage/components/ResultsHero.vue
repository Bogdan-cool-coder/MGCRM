<template>
  <!-- Results hero (ManagerCabinet-v2 §2.5): ring + mood + SecStat rows | TeamBars. -->
  <section class="results-hero">
    <!-- Loading -->
    <template v-if="loading">
      <div class="results-hero__pane">
        <Skeleton width="100%" height="140px" border-radius="12px" class="mb-3" />
        <Skeleton width="100%" height="18px" class="mb-2" />
        <Skeleton width="100%" height="18px" class="mb-2" />
        <Skeleton width="100%" height="18px" />
      </div>
      <div class="results-hero__pane results-hero__pane--right">
        <Skeleton
          v-for="n in 6"
          :key="n"
          width="100%"
          height="14px"
          class="mb-3"
        />
      </div>
    </template>

    <!-- Empty: no KPI data -->
    <template v-else-if="!kpi">
      <div class="results-hero__empty">
        <i class="pi pi-chart-pie results-hero__empty-icon" aria-hidden="true" />
        <span class="results-hero__empty-text">{{ t('managerCabinet.kpi.unavailable') }}</span>
      </div>
    </template>

    <!-- Loaded -->
    <template v-else>
      <!-- Left: results -->
      <div class="results-hero__pane">
        <span class="results-hero__eyebrow">
          {{ t('managerCabinet.results.eyebrow') }} · {{ periodLabel }}
        </span>

        <div class="results-hero__hero-row">
          <ScoreRing :pct="ringPct" :color="ringColor">
            <span class="results-hero__score">{{ scoreText }}</span>
            <span class="results-hero__score-label">
              {{ t('managerCabinet.results.scorePctLabel') }}
            </span>
          </ScoreRing>
          <MoodHead v-if="hasPlan" :score="kpi.personal.score_pct ?? 0" />
        </div>

        <SecStatRow
          :label="t('managerCabinet.kpi.personalSales')"
          :value="formatMoney(kpi.personal.income_fact_kopecks)"
          :sub="hasPlan
            ? `${t('managerCabinet.kpi.plan')} ${formatMoney(kpi.personal.income_plan_kopecks)}`
            : t('managerCabinet.kpi.noPlan')"
          :ccy="kpi.meta.multi_currency_warning"
        />
        <SecStatRow
          :label="t('managerCabinet.kpi.ftm')"
          :value="ftmValue"
          :sub="t('managerCabinet.kpi.ftmLabel')"
          :hint="t('managerCabinet.kpi.ftmHint')"
        />
        <SecStatRow
          :label="t('managerCabinet.kpi.rank')"
          :value="`#${kpi.team.rank} ${t('managerCabinet.team.of')} ${kpi.team.size}`"
          :sub="`${t('managerCabinet.team.avgLabel')} ${kpi.team.avg_pct}%`"
          :hint="t('managerCabinet.kpi.rankHint')"
        />
      </div>

      <!-- Right: team -->
      <div class="results-hero__pane results-hero__pane--right">
        <TeamBars
          :members="kpi.team.members"
          :avg-pct="kpi.team.avg_pct"
          :is-alone="kpi.team.size <= 1"
        />
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import ScoreRing from './ScoreRing.vue'
import MoodHead from './MoodHead.vue'
import SecStatRow from './SecStatRow.vue'
import TeamBars from './TeamBars.vue'
import type { KpiResponse } from '@/entities/managerCabinet'
import { formatMoney } from '@/utils/chartFormatters'
import { formatScorePct } from '@/utils/scoreFormat'

const props = defineProps<{
  kpi: KpiResponse | null
  loading: boolean
  periodLabel: string
}>()

const { t } = useI18n()

const hasPlan = computed<boolean>(() => props.kpi?.personal.has_salary_plan ?? false)

const scorePct = computed<number | null>(() => props.kpi?.personal.score_pct ?? null)

// Ring fill: 0 (empty) when no plan; else the score clamped 0..100 for the arc.
const ringPct = computed<number>(() => {
  if (!hasPlan.value || scorePct.value === null) return 0
  return scorePct.value
})

// Fill colour by band (§2.6): ≥100 green · 80–99 accent · <80 red.
const ringColor = computed<string>(() => {
  const pct = scorePct.value
  if (!hasPlan.value || pct === null) return 'var(--p-surface-300)'
  if (pct >= 100) return 'var(--p-green-500)'
  if (pct >= 80) return 'var(--p-primary-color)'
  return 'var(--p-red-500)'
})

const scoreText = computed<string>(() =>
  hasPlan.value ? formatScorePct(scorePct.value) : '—',
)

const ftmValue = computed<string>(() => {
  const p = props.kpi?.personal
  if (!p) return '—'
  return p.ftm_count_plan != null
    ? `${p.ftm_count_fact} / ${p.ftm_count_plan}`
    : String(p.ftm_count_fact)
})
</script>

<style lang="scss" scoped>
.results-hero {
  display: grid;
  grid-template-columns: 1fr 1fr;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.results-hero__pane {
  padding: $space-4 $space-5;
  min-width: 0;
}

.results-hero__pane--right {
  border-left: 1px solid $surface-200;

  .app-dark & {
    border-left-color: var(--p-surface-200);
  }
}

.results-hero__eyebrow {
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.results-hero__hero-row {
  display: flex;
  align-items: center;
  gap: $space-4;
  margin: $space-3 0 $space-1;
}

.results-hero__score {
  font-size: $font-size-2xl;
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}

.results-hero__score-label {
  margin-top: 2px;
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.results-hero__empty {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
}

.results-hero__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.results-hero__empty-text {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

// ── Tablet / mobile: stack panes, team under results ─────────────────────────
@media (max-width: 991px) {
  .results-hero {
    grid-template-columns: 1fr;
  }

  .results-hero__pane--right {
    border-left: none;
    border-top: 1px solid $surface-200;

    .app-dark & {
      border-top-color: var(--p-surface-200);
    }
  }
}
</style>
