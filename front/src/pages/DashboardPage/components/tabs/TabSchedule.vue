<template>
  <TabComingSoon :title="t('dashboard.hub.tab_schedule')" icon="pi pi-calendar" />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import TabComingSoon from './TabComingSoon.vue'

// Ф3 stub — NpCalendar + ECharts cumulative land with the R2 aggregator.
const { t } = useI18n()
</script>
