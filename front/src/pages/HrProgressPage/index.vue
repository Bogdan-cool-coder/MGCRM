<template>
  <div class="hr-progress-page">
    <PageHeader :title="t('onboarding.hrProgress.title')" icon="pi pi-chart-bar" />

    <!-- KPI cards -->
    <HrKpiCards :summary="summary" :loading="loadingSummary" />

    <!-- Charts row -->
    <div class="row g-4 mb-4">
      <div class="col-lg-5">
        <HrStatusPieChart :summary="summary" :loading="loadingSummary" />
      </div>
      <div class="col-lg-7">
        <HrTopCoursesChart :summary="summary" :loading="loadingSummary" />
      </div>
    </div>

    <!-- Progress matrix -->
    <Card>
      <template #title>{{ t('onboarding.hrProgress.tableTitle') }}</template>
      <template #content>
        <HrProgressFilterPanel
          :filters="filters"
          @change="applyFilters"
          @reset="resetFilters"
        />
        <HrProgressTable
          :rows="progressRows"
          :loading="loadingRows"
          :total-rows="totalRows"
          @page="onPage"
        />
      </template>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import HrKpiCards from './components/HrKpiCards.vue'
import HrStatusPieChart from './components/HrStatusPieChart.vue'
import HrTopCoursesChart from './components/HrTopCoursesChart.vue'
import HrProgressFilterPanel from './components/HrProgressFilterPanel.vue'
import HrProgressTable from './components/HrProgressTable.vue'
import { useHrProgressPage } from './composables/useHrProgressPage'

const { t } = useI18n()

const {
  summary,
  loadingSummary,
  progressRows,
  loadingRows,
  totalRows,
  filters,
  loadSummary,
  loadProgress,
  onPage,
  applyFilters,
  resetFilters,
} = useHrProgressPage()

onMounted(async () => {
  await Promise.all([loadSummary(), loadProgress()])
})
</script>

<style lang="scss" scoped>
.hr-progress-page {
  // wrapper
}
</style>
