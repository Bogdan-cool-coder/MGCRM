<template>
  <div class="hr-progress-page">
    <ListToolbar
      icon="pi-chart-bar"
      :title="t('onboarding.hrProgress.title')"
    />

    <div class="hr-progress-page__body">
      <!-- KPI cards -->
      <HrKpiCards :summary="summary" :loading="loadingSummary" />

      <!-- Charts row -->
      <div class="row g-4 hr-progress-page__charts">
        <div class="col-lg-5">
          <HrStatusPieChart :summary="summary" :loading="loadingSummary" />
        </div>
        <div class="col-lg-7">
          <HrTopCoursesChart :summary="summary" :loading="loadingSummary" />
        </div>
      </div>

      <!-- Progress matrix -->
      <div class="hr-progress-page__matrix">
        <div class="hr-progress-page__matrix-head">
          <h2 class="hr-progress-page__matrix-title">{{ t('onboarding.hrProgress.tableTitle') }}</h2>
          <HrProgressFilterPanel
            :filters="filters"
            @change="applyFilters"
            @reset="resetFilters"
          />
        </div>
        <HrProgressTable
          :rows="progressRows"
          :loading="loadingRows"
          :total-rows="totalRows"
          @page="onPage"
        />
        <PaginatorCaption
          :page="filters.page"
          :per-page="filters.per_page"
          :total="totalRows"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ListToolbar from '@/components/shared/ListToolbar.vue'
import PaginatorCaption from '@/components/shared/PaginatorCaption.vue'
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
  display: flex;
  flex-direction: column;
  height: 100%;
}

.hr-progress-page__body {
  padding: $space-4 $space-6;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.hr-progress-page__charts {
  margin: 0;
}

.hr-progress-page__matrix {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
}

.hr-progress-page__matrix-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
  padding: $space-4 $space-4 $space-3;
  border-bottom: 1px solid $surface-200;
}

.hr-progress-page__matrix-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  margin: 0;
}
</style>
