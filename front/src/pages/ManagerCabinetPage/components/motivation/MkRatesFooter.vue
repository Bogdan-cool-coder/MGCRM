<template>
  <div class="mk-rates" :class="{ 'mk-rates--stale': stale }">
    <i
      :class="stale ? 'pi pi-exclamation-triangle' : 'pi pi-refresh'"
      class="mk-rates__icon"
      aria-hidden="true"
    />
    <span class="mk-rates__label">
      {{ t('motivation.card.rates_as_of', { date: formattedDate }) }}:
    </span>
    <span class="mk-rates__pairs">{{ pairsText }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { isRatesStale } from '@/utils/motivation'
import type { MkRates } from '@/entities/motivation'

const props = defineProps<{
  rates: MkRates
}>()

const { t, locale } = useI18n()

const stale = computed<boolean>(() => isRatesStale(props.rates.date))

const formattedDate = computed<string>(() => {
  const d = new Date(props.rates.date)
  if (!Number.isFinite(d.getTime())) return props.rates.date
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'ru-RU')
})

const pairsText = computed<string>(() =>
  props.rates.pairs
    .map((p) => `1 ${p.from} = ${Number(p.rate).toLocaleString('ru-RU')} ${p.to}`)
    .join('  ·  '),
)
</script>

<style lang="scss" scoped>
.mk-rates {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-2 $space-5;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    border-color: var(--p-surface-200);
    color: var(--p-surface-600);
  }

  &--stale {
    color: var(--p-orange-500);
  }
}

.mk-rates__icon {
  font-size: $font-size-xs;
}

.mk-rates__pairs {
  font-variant-numeric: tabular-nums;
}
</style>
