<template>
  <div class="mkb-card mkb-currency">
    <header class="mkb-card__head">
      <span class="mkb-eyebrow">{{ t('motivation.builder.step_currency') }}</span>
    </header>

    <div class="mkb-currency__base">
      <label class="mkb-label">{{ t('motivation.builder.base_currency_label') }}</label>
      <Select
        :model-value="baseCurrency"
        :options="currencyOptions"
        option-label="label"
        option-value="value"
        :disabled="disabled"
        class="mkb-currency__select"
        @update:model-value="(v) => emit('update:baseCurrency', v as string)"
      />
    </div>

    <!-- Read-only auto rates (ОВ-1: no manual inputs) -->
    <div class="mkb-currency__rates">
      <div class="mkb-currency__rates-head">
        <span class="mkb-currency__rates-title">
          {{ t('motivation.builder.rates_auto_title') }}
        </span>
        <Button
          text
          size="small"
          severity="secondary"
          icon="pi pi-refresh"
          :label="t('motivation.builder.rates_refresh')"
          :loading="ratesLoading"
          :disabled="disabled"
          @click="emit('refresh')"
        />
      </div>

      <div v-if="autoRates.length" class="mkb-currency__rate-list">
        <div v-for="r in autoRates" :key="`${r.from}-${r.to}`" class="mkb-currency__rate">
          <span class="mkb-currency__rate-pair">
            1 {{ r.from }} = {{ r.rate.toLocaleString('ru-RU') }} {{ r.to }}
          </span>
          <span class="mkb-currency__rate-meta">
            {{ sourceLabel(r.source) }} · {{ formatDate(r.date) }}
          </span>
        </div>
      </div>
      <p v-else class="mkb-hint">{{ t('motivation.builder.rates_empty') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Button from 'primevue/button'
import type { AutoRate } from './useMotivationBuilder'

defineProps<{
  baseCurrency: string
  currencyOptions: { label: string; value: string }[]
  autoRates: AutoRate[]
  ratesLoading: boolean
  disabled: boolean
}>()

const emit = defineEmits<{
  'update:baseCurrency': [value: string]
  refresh: []
}>()

const { t, locale } = useI18n()

const sourceLabel = (source: string): string =>
  source === 'manual'
    ? t('motivation.builder.rates_source_manual')
    : t('motivation.builder.rates_source_cbr')

const formatDate = (iso: string): string => {
  const d = new Date(iso)
  if (!Number.isFinite(d.getTime())) return iso
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'ru-RU')
}
</script>

<style lang="scss" scoped>
@use '@/pages/SettingsPage/components/sections/motivation/mkb-shared' as *;

.mkb-currency__base {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  margin-bottom: $space-4;
}

.mkb-currency__select {
  width: 160px;
}

.mkb-currency__rates {
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.mkb-currency__rates-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  margin-bottom: $space-2;
}

.mkb-currency__rates-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mkb-currency__rate-list {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.mkb-currency__rate {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
}

.mkb-currency__rate-pair {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mkb-currency__rate-meta {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
