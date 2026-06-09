<template>
  <Message severity="success" :closable="false" class="chat-report-banner">
    <span>{{ t('reportBanner.created') }}<template v-if="reportTitle">: {{ reportTitle }}</template>. </span>
    <router-link :to="`/reports/${reportId}`" class="report-link">{{ t('reportBanner.open') }} →</router-link>
  </Message>
</template>

<script setup lang="ts">
import Message from 'primevue/message'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  reportId: number
  reportTitle?: string | null
}

defineProps<Props>()
const { t } = useLocalI18n({ en, ru })
</script>

<style lang="scss" scoped>
.chat-report-banner {
  margin: 0.5rem 1rem;
  border-radius: 8px;
}

.report-link {
  font-weight: $font-weight-medium;
  color: inherit;
  text-decoration: underline;

  &:hover {
    opacity: 0.8;
  }
}
</style>
