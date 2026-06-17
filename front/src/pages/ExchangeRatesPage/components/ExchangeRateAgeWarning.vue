<template>
  <Message v-if="isStale" severity="warn" class="age-warning">
    <div class="age-warning__content">
      <span>{{ t('catalog.exchangeRates.page.ageWarning.message') }}</span>
      <Button
        :label="t('catalog.exchangeRates.page.ageWarning.refreshNow')"
        severity="warn"
        text
        size="small"
        :loading="refreshing"
        @click="$emit('refresh')"
      />
    </div>
  </Message>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Button from 'primevue/button'

defineProps<{
  isStale: boolean
  refreshing?: boolean
}>()

defineEmits<{
  refresh: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.age-warning {
  &__content {
    display: flex;
    align-items: center;
    gap: $space-3;
    flex-wrap: wrap;
  }
}
</style>
