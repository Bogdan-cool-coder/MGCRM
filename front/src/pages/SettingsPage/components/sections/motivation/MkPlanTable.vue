<template>
  <div class="mkb-card mkb-plan">
    <header class="mkb-card__head">
      <span class="mkb-eyebrow">{{ t('motivation.builder.step_plan') }}</span>
    </header>

    <DataTable :value="enabledRows" size="small" show-gridlines class="mkb-plan__table">
      <Column :header="t('motivation.card.salary_components')" style="width: 200px">
        <template #body="{ data }">
          <div class="mkb-plan__pos">
            <i :class="MK_ITEM_ICONS[(data as PlanRowForm).kind]" class="mkb-plan__pos-icon" aria-hidden="true" />
            <span>{{ (data as PlanRowForm).name }}</span>
          </div>
        </template>
      </Column>

      <Column :header="t('motivation.builder.indicator')" style="width: 200px">
        <template #body="{ data }">
          <!-- KPI: name + type -->
          <template v-if="(data as PlanRowForm).kind === 'kpi'">
            <div class="mkb-plan__kpi">
              <InputText
                :model-value="(data as PlanRowForm).name"
                :disabled="disabled"
                :placeholder="t('motivation.builder.kpi_name')"
                class="mkb-plan__kpi-name"
                @update:model-value="(v) => ((data as PlanRowForm).name = v ?? '')"
              />
              <Select
                :model-value="(data as PlanRowForm).kpiType"
                :options="kpiTypeOptions"
                option-label="label"
                option-value="value"
                :disabled="disabled"
                class="mkb-plan__kpi-type"
                @update:model-value="(v) => ((data as PlanRowForm).kpiType = v as KpiType)"
              />
            </div>
          </template>
          <span v-else class="mkb-plan__rule-text">{{ indicatorText(data as PlanRowForm) }}</span>
        </template>
      </Column>

      <Column :header="t('motivation.builder.plan_label')" style="width: 170px">
        <template #body="{ data }">
          <!-- KPI count → plan_count; manual → n/a; else money -->
          <template v-if="(data as PlanRowForm).kind === 'kpi'">
            <InputNumber
              v-if="(data as PlanRowForm).kpiType === 'count'"
              :model-value="(data as PlanRowForm).kpiPlanCount"
              :disabled="disabled"
              :min="0"
              locale="ru-RU"
              class="mkb-plan__input"
              @update:model-value="(v) => ((data as PlanRowForm).kpiPlanCount = v)"
            />
            <span v-else class="mkb-plan__na">—</span>
          </template>
          <InputNumber
            v-else
            :model-value="(data as PlanRowForm).planAmount"
            :disabled="disabled"
            :min="0"
            :min-fraction-digits="0"
            locale="ru-RU"
            class="mkb-plan__input"
            @update:model-value="(v) => ((data as PlanRowForm).planAmount = v)"
          />
        </template>
      </Column>

      <Column :header="t('motivation.builder.currency_label')" style="width: 110px">
        <template #body="{ data }">
          <Select
            :model-value="(data as PlanRowForm).currency"
            :options="currencyOptions"
            option-label="label"
            option-value="value"
            :disabled="disabled"
            class="mkb-plan__currency"
            @update:model-value="(v) => ((data as PlanRowForm).currency = v as string)"
          />
        </template>
      </Column>

      <Column :header="t('motivation.builder.rule_label')" style="width: 200px">
        <template #body="{ data }">
          <!-- Commission: % input + tooltip -->
          <div v-if="(data as PlanRowForm).kind === 'commission'" class="mkb-plan__commission">
            <InputNumber
              :model-value="(data as PlanRowForm).commissionPct"
              :disabled="disabled"
              :min="0"
              :max="100"
              suffix=" %"
              class="mkb-plan__pct-input"
              @update:model-value="(v) => ((data as PlanRowForm).commissionPct = v)"
            />
            <i
              v-tooltip.top="t('motivation.builder.commission_pct_hint')"
              class="pi pi-question-circle mkb-plan__help"
              aria-hidden="true"
            />
          </div>
          <!-- KPI amount → salary per completion -->
          <InputNumber
            v-else-if="(data as PlanRowForm).kind === 'kpi'"
            :model-value="(data as PlanRowForm).kpiSalaryPerCompletion"
            :disabled="disabled"
            :min="0"
            locale="ru-RU"
            :placeholder="t('motivation.builder.kpi_salary')"
            class="mkb-plan__input"
            @update:model-value="(v) => ((data as PlanRowForm).kpiSalaryPerCompletion = v)"
          />
          <span v-else class="mkb-plan__na">—</span>
        </template>
      </Column>
    </DataTable>

    <small v-if="errors.base" class="mkb-error">{{ errors.base }}</small>
    <small v-if="errors.commission" class="mkb-error">{{ errors.commission }}</small>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { MK_ITEM_ICONS, type KpiType } from '@/entities/motivation'
import type { PlanRowForm } from './useMotivationBuilder'

const props = defineProps<{
  rows: PlanRowForm[]
  currencyOptions: { label: string; value: string }[]
  errors: Record<string, string>
  disabled: boolean
}>()

const { t } = useI18n()

const enabledRows = computed<PlanRowForm[]>(() => props.rows.filter((r) => r.enabled))

const kpiTypeOptions = computed(() => [
  { label: t('motivation.builder.kpi_type_count'), value: 'count' as const },
  { label: t('motivation.builder.kpi_type_amount'), value: 'amount' as const },
  { label: t('motivation.builder.kpi_type_manual'), value: 'manual' as const },
])

const indicatorText = (row: PlanRowForm): string => {
  switch (row.kind) {
    case 'commission':
      return t('motivation.builder.indicator_income')
    case 'base_salary':
      return t('motivation.builder.indicator_fixed')
    case 'bonus':
      return t('motivation.builder.indicator_oneoff')
    default:
      return '—'
  }
}
</script>

<style lang="scss" scoped>
@use '@/pages/SettingsPage/components/sections/motivation/mkb-shared' as *;

.mkb-plan__table {
  :deep(.p-datatable-tbody > tr > td) {
    padding: $space-2 $space-3;
  }
}

.mkb-plan__pos {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-weight: $font-weight-medium;
}

.mkb-plan__pos-icon {
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.mkb-plan__input,
.mkb-plan__currency,
.mkb-plan__kpi-name,
.mkb-plan__kpi-type,
.mkb-plan__pct-input {
  width: 100%;
}

.mkb-plan__kpi {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.mkb-plan__commission {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.mkb-plan__help {
  color: $surface-500;
  cursor: help;
}

.mkb-plan__rule-text {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mkb-plan__na {
  color: $surface-400;
}

:deep(.p-inputnumber-input) {
  text-align: right;
}
</style>
