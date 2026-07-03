<template>
  <div v-if="kpiItems.length" class="mk-kpi-strip">
    <div
      v-for="(item, idx) in kpiItems"
      :key="`kpi-${idx}`"
      class="mk-card mk-kpi"
    >
      <div class="mk-kpi__head">
        <i :class="MK_ITEM_ICONS.kpi" class="mk-kpi__icon" aria-hidden="true" />
        <span class="mk-eyebrow">{{ item.name }}</span>
      </div>

      <!-- Manual KPI: done / not-done badge instead of a number -->
      <template v-if="isManual(item)">
        <div class="mk-kpi__manual">
          <Tag
            :severity="item.params?.manual_done ? 'success' : 'danger'"
            :value="item.params?.manual_done
              ? t('motivation.card.manual_done')
              : t('motivation.card.manual_not_done')"
          />
        </div>
      </template>

      <!-- Count / amount KPI -->
      <template v-else>
        <div class="mk-kpi__value-row">
          <span class="mk-kpi__value">{{ factValue(item) }}</span>
          <PctTag :value="item.pct" :badge="item.badge" size="md" />
        </div>
        <span class="mk-kpi__sub">
          {{ t('motivation.card.col_plan') }}: {{ planValue(item) }}
        </span>
      </template>

      <!-- Multi-currency footnote for amount KPI with breakdown -->
      <button
        v-if="hasBreakdown(item)"
        type="button"
        class="mk-kpi__multirate"
        @click="openBreakdown($event, item)"
      >
        <i class="pi pi-info-circle" aria-hidden="true" />
        {{ t('motivation.card.multirate_breakdown') }}
      </button>
    </div>

    <Popover ref="popover">
      <div class="mk-kpi__pop">
        <div
          v-for="row in activeBreakdown"
          :key="row.deal_id"
          class="mk-kpi__pop-row"
        >
          <span>{{ row.company_name }}</span>
          <span class="mk-kpi__pop-amount">
            {{ formatMkMoney(row.amount_kopecks, row.currency) }}
          </span>
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import Popover from 'primevue/popover'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkMoney, MK_EMPTY } from '@/utils/motivation'
import {
  MK_ITEM_ICONS,
  type MkCardItem,
  type MkCommissionBreakdownRow,
} from '@/entities/motivation'

const props = defineProps<{
  items: MkCardItem[]
}>()

const { t } = useI18n()

/** Only kpi-kind items render as indicator cards (flexible, ОВ-5). */
const kpiItems = computed<MkCardItem[]>(() =>
  props.items.filter((i) => i.kind === 'kpi'),
)

const isManual = (item: MkCardItem): boolean => item.params?.kpi_type === 'manual'
const isCount = (item: MkCardItem): boolean => item.params?.kpi_type === 'count'

const factValue = (item: MkCardItem): string => {
  if (isCount(item)) {
    const n = item.params?.fact_count
    return n != null ? String(n) : MK_EMPTY
  }
  return formatMkMoney(item.fact_amount_kopecks, item.currency)
}

const planValue = (item: MkCardItem): string => {
  if (isCount(item)) {
    const n = item.params?.plan_count
    return n != null ? String(n) : MK_EMPTY
  }
  return formatMkMoney(item.plan_amount_kopecks, item.currency)
}

const hasBreakdown = (item: MkCardItem): boolean =>
  !!item.params?.breakdown && item.params.breakdown.length > 0

const popover = ref<InstanceType<typeof Popover> | null>(null)
const activeBreakdown = ref<MkCommissionBreakdownRow[]>([])

const openBreakdown = (event: Event, item: MkCardItem): void => {
  activeBreakdown.value = item.params?.breakdown ?? []
  popover.value?.toggle(event)
}
</script>

<style lang="scss" scoped>
@use '@/pages/ManagerCabinetPage/components/motivation/mk-shared' as *;

.mk-kpi-strip {
  display: flex;
  gap: $space-4;
  flex-wrap: wrap;
}

.mk-kpi {
  flex: 1;
  min-width: 260px;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.mk-kpi__head {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.mk-kpi__icon {
  font-size: $font-size-xl;
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.mk-kpi__value-row {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.mk-kpi__value {
  font-size: $font-size-2xl;
  font-weight: $font-weight-bold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mk-kpi__sub {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-kpi__manual {
  padding: $space-1 0;
}

.mk-kpi__multirate {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  margin-top: auto;
  padding: 0;
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: $font-size-xs;
  color: $surface-600;
  text-align: left;

  &:hover {
    color: var(--p-primary-color);
  }

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-kpi__pop {
  display: flex;
  flex-direction: column;
  min-width: 240px;
}

.mk-kpi__pop-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  padding: $space-1 $space-1;
  font-size: $font-size-sm;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.mk-kpi__pop-amount {
  font-weight: $font-weight-semibold;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 767px) {
  .mk-kpi-strip {
    flex-direction: column;
  }
}
</style>
