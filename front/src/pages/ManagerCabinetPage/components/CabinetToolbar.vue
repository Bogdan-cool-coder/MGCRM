<template>
  <!--
    Unified cabinet toolbar (ManagerCabinet-v2 §2.1): one row — icon tile + title
    + admin user-picker + segmented tabs + month dropdown. Replaces the old
    PageHeader + tab strip + separate picker row.
  -->
  <div class="cabinet-toolbar">
    <span class="cabinet-toolbar__icon-tile">
      <i class="pi pi-id-card" aria-hidden="true" />
    </span>

    <div class="cabinet-toolbar__heading">
      <h1 class="cabinet-toolbar__title">{{ t('managerCabinet.title') }}</h1>
      <div v-if="subtitle" class="cabinet-toolbar__subtitle">{{ subtitle }}</div>
    </div>

    <span class="cabinet-toolbar__spacer" />

    <!-- User-picker (admin / director only) -->
    <Select
      v-if="canViewOthers"
      :model-value="viewedUserId"
      :options="memberSelectOptions"
      option-label="label"
      option-value="value"
      :placeholder="t('managerCabinet.viewing.self')"
      show-clear
      filter
      :filter-placeholder="t('managerCabinet.viewing.placeholder')"
      class="cabinet-toolbar__picker"
      @update:model-value="(v) => emit('update:viewedUser', (v as number | null) ?? null)"
    >
      <template #value="{ value }">
        <span v-if="value == null" class="cabinet-toolbar__picker-self">
          {{ t('managerCabinet.viewing.self') }}
        </span>
        <span v-else class="cabinet-toolbar__picker-selected">
          <EntityAvatar :name="labelForUser(value as number)" :pixel-size="22" />
          <span class="cabinet-toolbar__picker-name">{{ labelForUser(value as number) }}</span>
        </span>
      </template>
      <template #option="{ option }">
        <span class="cabinet-toolbar__picker-option">
          <EntityAvatar :name="option.label" :pixel-size="26" />
          <span class="cabinet-toolbar__picker-name">{{ option.label }}</span>
        </span>
      </template>
    </Select>

    <!-- Segmented tabs -->
    <SelectButton
      :model-value="activeTab"
      :options="tabOptions"
      option-label="label"
      option-value="value"
      :allow-empty="false"
      class="cabinet-toolbar__segmented"
      @update:model-value="(v) => emit('update:activeTab', v as CabinetTab)"
    />

    <!-- Month dropdown — bound to whichever tab is active -->
    <Select
      v-if="activeTab === 'overview'"
      :model-value="overviewPeriod"
      :options="overviewMonthOptions"
      option-label="label"
      option-value="value"
      class="cabinet-toolbar__month"
      @update:model-value="(v) => emit('update:overviewPeriod', v as string)"
    />
    <Select
      v-else
      :model-value="motivationMonthValue"
      :options="motivationMonthOptions"
      option-label="label"
      option-value="value"
      class="cabinet-toolbar__month"
      @update:model-value="(v) => emit('update:motivationMonth', v as string)"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import type { MonthOption } from '../composables/useMotivationMonths'

type CabinetTab = 'overview' | 'motivation'

interface MemberOption {
  label: string
  value: number
}

interface PeriodOption {
  label: string
  value: string
}

const props = defineProps<{
  subtitle: string | null
  activeTab: CabinetTab
  canViewOthers: boolean
  viewedUserId: number | null
  memberSelectOptions: MemberOption[]
  /** Overview KPI period value ('current_month' | 'YYYY-MM'). */
  overviewPeriod: string
  overviewMonthOptions: PeriodOption[]
  /** Motivation month value ('YYYY-M'). */
  motivationMonthValue: string
  motivationMonthOptions: MonthOption[]
}>()

const emit = defineEmits<{
  'update:viewedUser': [number | null]
  'update:activeTab': [CabinetTab]
  'update:overviewPeriod': [string]
  'update:motivationMonth': [string]
}>()

const { t } = useI18n()

const tabOptions = computed(() => [
  { label: t('managerCabinet.title'), value: 'overview' as const },
  { label: t('motivation.card.title'), value: 'motivation' as const },
])

const labelForUser = (id: number): string =>
  props.memberSelectOptions.find((o) => o.value === id)?.label ?? `#${id}`
</script>

<style lang="scss" scoped>
.cabinet-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  padding: $space-3 $space-5;
  background: $surface-card;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.cabinet-toolbar__icon-tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: $radius-md;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  font-size: $font-size-lg;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-100);
  }
}

.cabinet-toolbar__heading {
  min-width: 0;
}

.cabinet-toolbar__title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  line-height: $line-height-tight;
}

.cabinet-toolbar__subtitle {
  margin-top: 2px;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.cabinet-toolbar__spacer {
  flex: 1;
  min-width: $space-3;
}

.cabinet-toolbar__picker {
  min-width: 210px;
}

.cabinet-toolbar__picker-self {
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.cabinet-toolbar__picker-selected,
.cabinet-toolbar__picker-option {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  min-width: 0;
}

.cabinet-toolbar__picker-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cabinet-toolbar__month {
  min-width: 150px;
}

@media (max-width: 767px) {
  .cabinet-toolbar__picker,
  .cabinet-toolbar__month {
    min-width: 0;
    flex: 1 1 160px;
  }
}
</style>
