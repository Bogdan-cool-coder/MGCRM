<template>
  <Tag :value="label" :severity="severity" :size="size" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import type { PricingType } from '@/entities/catalog'

const props = withDefaults(
  defineProps<{
    pricingType: PricingType | string
    size?: 'small' | 'large' | 'normal' | undefined
  }>(),
  {
    size: undefined,
  },
)

const { t } = useI18n()

const label = computed(() =>
  t(`catalog.products.pricingType.${props.pricingType}`, props.pricingType),
)

const severity = computed(() => {
  switch (props.pricingType) {
    case 'fixed':
      return 'success'
    case 'tiered':
      return 'info'
    case 'per_minute':
      return 'warn'
    case 'package':
      return 'warn'
    case 'custom':
      return 'secondary'
    default:
      return 'secondary'
  }
})
</script>
