<template>
  <section class="mk-card mk-dept-plan">
    <header class="mk-card__head">
      <span class="mk-dept-plan__eyebrow">
        <span class="mk-eyebrow">{{ t('motivation.card.dept_plan') }}</span>
        <i
          v-tooltip.top="t('motivation.card.dept_plan_hint')"
          class="pi pi-info-circle mk-dept-plan__hint"
          aria-hidden="true"
        />
      </span>
      <span v-if="pipelineName" class="mk-card__head-meta">
        {{ pipelineName }} · {{ periodLabel }}
      </span>
    </header>

    <!-- No plan set -->
    <div v-if="!deptPlan" class="mk-dept-plan__empty">
      <i class="pi pi-exclamation-circle mk-dept-plan__empty-icon" />
      <span class="mk-dept-plan__empty-text">{{ t('motivation.card.no_dept_plan') }}</span>
    </div>

    <!-- Plan → progress → fact -->
    <div v-else class="mk-dept-plan__body">
      <div class="mk-dept-plan__plan">
        <span class="mk-dept-plan__num-value">
          {{ formatMkMoney(deptPlan.target_kopecks, deptPlan.target_currency) }}
        </span>
        <span class="mk-label">{{ t('motivation.card.col_plan') }}</span>
      </div>

      <div class="mk-dept-plan__bar">
        <span class="mk-dept-plan__track">
          <span class="mk-dept-plan__fill" />
          <span class="mk-dept-plan__mask" :style="{ left: `${barValue}%` }" />
        </span>
      </div>

      <div class="mk-dept-plan__fact">
        <div class="mk-dept-plan__fact-row">
          <span class="mk-dept-plan__num-value mk-dept-plan__num-value--fact">
            {{ formatMkMoney(deptPlan.fact_kopecks, deptPlan.target_currency) }}
          </span>
          <PctTag :value="deptPlan.pct" :badge="deptPlan.badge" size="md" quiet />
        </div>
        <span class="mk-label">{{ t('motivation.card.col_fact') }}</span>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkMoney } from '@/utils/motivation'
import type { MkDeptPlan } from '@/entities/motivation'

const props = defineProps<{
  deptPlan: MkDeptPlan | null
  pipelineName: string | null
  periodLabel: string
}>()

const { t } = useI18n()

const barValue = computed<number>(() => {
  if (!props.deptPlan || props.deptPlan.pct === null) return 0
  return Math.min(props.deptPlan.pct, 100)
})
</script>

<style lang="scss" scoped>
@use '@/pages/ManagerCabinetPage/components/motivation/mk-shared' as *;

.mk-dept-plan__body {
  display: flex;
  align-items: center;
  gap: $space-6;
  flex-wrap: wrap;
}

.mk-dept-plan__plan,
.mk-dept-plan__fact {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.mk-dept-plan__bar {
  flex: 1;
  min-width: 120px;
}

// Gradient «health-scale» bar (consistent with TeamBars §2.10): red→amber→green
// revealed up to the fact pct via a surface mask on the right.
.mk-dept-plan__track {
  position: relative;
  display: block;
  height: 9px;
  border-radius: $radius-pill;
  background: var(--p-surface-100);
  overflow: hidden;
}

.mk-dept-plan__fill {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    90deg,
    var(--p-red-500) 0%,
    var(--p-orange-400) 52%,
    var(--p-green-500) 100%
  );
}

.mk-dept-plan__mask {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  background: var(--p-surface-100);
}

.mk-dept-plan__eyebrow {
  display: inline-flex;
  align-items: center;
}

.mk-dept-plan__hint {
  margin-left: $space-1;
  font-size: $font-size-2xs;
  color: $surface-600;
  cursor: help;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-dept-plan__fact-row {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.mk-dept-plan__num-value {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;

  &--fact {
    font-weight: $font-weight-bold;
  }
}

.mk-dept-plan__empty {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.mk-dept-plan__empty-icon {
  font-size: $font-size-lg;
  color: var(--p-orange-500);
}

.mk-dept-plan__empty-text {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}
</style>
