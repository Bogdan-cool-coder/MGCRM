<template>
  <InfoPanel
    :title="t('crm.company.sections.deals')"
    icon="pi-briefcase"
    panel-key="company-deals-mini"
    :count="openCount"
    :default-collapsed="false"
  >
    <template #header-action>
      <Button
        icon="pi pi-plus"
        size="small"
        text
        severity="secondary"
        :aria-label="t('company.page.deals.createDeal')"
        @click.stop="$emit('createDeal')"
      />
    </template>

    <!-- Loading -->
    <div v-if="loading" class="mini-pipeline__skeleton">
      <Skeleton height="36px" class="mb-2" />
      <Skeleton height="20px" />
    </div>

    <!-- Empty -->
    <div v-else-if="deals.length === 0" class="mini-pipeline__empty">
      <i class="pi pi-briefcase mini-pipeline__empty-icon" />
      <p class="mini-pipeline__empty-text">{{ t('company.page.deals.empty') }}</p>
      <p class="mini-pipeline__empty-hint">{{ t('company.page.deals.emptyHint') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('company.page.deals.createDeal')"
        size="small"
        severity="secondary"
        outlined
        @click="$emit('createDeal')"
      />
    </div>

    <!-- Mini funnel -->
    <template v-else>
      <!-- Stage chips row -->
      <div class="mini-pipeline__stages">
        <button
          v-for="stage in stageGroups"
          :key="stage.stageId"
          type="button"
          class="mini-pipeline__stage-chip"
          :style="{ borderColor: stage.color ?? 'var(--p-surface-300)' }"
          :title="stage.stageName"
          @click="$emit('filterByStage', stage.stageId)"
        >
          <span
            class="mini-pipeline__stage-dot"
            :style="{ background: stage.color ?? 'var(--p-surface-400)' }"
          />
          <span class="mini-pipeline__stage-name">{{ stage.stageName }}</span>
          <span class="mini-pipeline__stage-count">{{ stage.count }}</span>
        </button>
      </div>

      <!-- Aggregate line -->
      <div class="mini-pipeline__aggregate">
        <span class="mini-pipeline__aggregate-count">{{ t('company.page.deals.openCount', { count: openCount }) }}</span>
        <span v-if="totalAmount" class="mini-pipeline__aggregate-sum">
          · {{ formatAmount(totalAmount) }}
        </span>
      </div>

      <!-- View all link -->
      <button
        type="button"
        class="mini-pipeline__see-all"
        @click="$emit('goToTab', 'deals')"
      >
        {{ t('company.page.deals.seeAll') }}
        <i class="pi pi-arrow-right" />
      </button>
    </template>
  </InfoPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import type { DealDto } from '@/entities/sales'

interface StageGroup {
  stageId: number
  stageName: string
  color: string | null
  count: number
}

const props = defineProps<{
  deals: DealDto[]
  loading: boolean
}>()

defineEmits<{
  createDeal: []
  filterByStage: [stageId: number]
  goToTab: [tab: string]
}>()

const { t } = useI18n()

const openDeals = computed(() => props.deals.filter((d) => d.status === 'open'))

const openCount = computed(() => openDeals.value.length)

const stageGroups = computed((): StageGroup[] => {
  const map = new Map<number, StageGroup>()
  for (const deal of openDeals.value) {
    const sid = deal.stage.id
    const existing = map.get(sid)
    if (existing) {
      existing.count++
    } else {
      map.set(sid, {
        stageId: sid,
        stageName: deal.stage.name,
        color: deal.stage.color,
        count: 1,
      })
    }
  }
  return Array.from(map.values())
})

const totalAmount = computed(() => {
  // Sum in kopecks — only when all deals share same currency; otherwise show count only
  const currencies = new Set(openDeals.value.map((d) => d.currency))
  if (currencies.size !== 1) return null
  return openDeals.value.reduce((s, d) => s + d.amount, 0)
})

function formatAmount(kopecks: number): string {
  // Display as integer currency units (kopecks / 100)
  const currency = openDeals.value[0]?.currency ?? 'RUB'
  const units = Math.round(kopecks / 100)
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(units)
  } catch {
    return `${units.toLocaleString('ru-RU')} ${currency}`
  }
}
</script>

<style lang="scss" scoped>
.mini-pipeline__skeleton {
  display: flex;
  flex-direction: column;
  padding: 0 0 $space-3;
}

.mini-pipeline__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-6 $space-4;
  text-align: center;
}

.mini-pipeline__empty-icon {
  font-size: $font-size-icon-lg; // 2rem
  color: $surface-300;
}

.mini-pipeline__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.mini-pipeline__empty-hint {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.mini-pipeline__stages {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  padding-bottom: $space-3;
}

.mini-pipeline__stage-chip {
  display: flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: 1px solid;
  border-radius: $radius-sm;
  padding: $space-1 $space-2;
  cursor: pointer;
  transition: background var(--app-transition-fast);
  font-size: $font-size-xs;

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-800);
    }
  }
}

.mini-pipeline__stage-dot {
  width: 8px;
  height: 8px;
  border-radius: $radius-circle; // 50%
  flex-shrink: 0;
}

.mini-pipeline__stage-name {
  color: $surface-700;
  font-weight: $font-weight-medium;
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.mini-pipeline__stage-count {
  color: $surface-500;
  font-weight: $font-weight-semibold;
  flex-shrink: 0;
}

.mini-pipeline__aggregate {
  font-size: $font-size-xs;
  color: $surface-500;
  padding-bottom: $space-2;
}

.mini-pipeline__aggregate-count {
  font-weight: $font-weight-semibold;
}

.mini-pipeline__aggregate-sum {
  margin-left: $space-1;
}

.mini-pipeline__see-all {
  display: flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: $space-1 0;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;

  i {
    font-size: $font-size-3xs; // 10px
  }
}
</style>
