<template>
  <div class="manager-cabinet-page">
    <PageHeader :title="t('managerCabinet.title')" icon="pi pi-id-card" />

    <div class="manager-cabinet-page__content">
      <!-- Cross-user picker (admin / director only) -->
      <div v-if="canViewOthers" class="row g-4 mb-4">
        <div class="col-12 col-md-6 col-lg-4">
          <label class="manager-cabinet-page__picker-label">
            {{ t('managerCabinet.viewing.label') }}
          </label>
          <Select
            :model-value="viewedUserId"
            :options="memberSelectOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('managerCabinet.viewing.self')"
            show-clear
            filter
            class="w-100"
            @update:model-value="(v) => setViewedUser(v as number | null)"
          />
        </div>
      </div>

      <!-- Row 1: CabinetHeader -->
      <div class="row g-4 mb-4">
        <div class="col-12">
          <CabinetHeader
            :profile="profile"
            :loading="profileLoading"
          />
        </div>
      </div>

      <!-- Row 2: MonthStepper -->
      <div class="row g-4 mb-4">
        <div class="col-12">
          <MonthStepper
            :period="period"
            @update:period="setPeriod"
          />
        </div>
      </div>

      <!-- Multi-currency warning -->
      <Message
        v-if="kpi?.meta?.multi_currency_warning"
        severity="warn"
        :closable="false"
        icon="pi pi-info-circle"
        class="mb-4"
      >
        {{ t('managerCabinet.multiCurrencyWarning') }}
      </Message>

      <!-- Row 3: KpiCards -->
      <div class="row g-4 mb-4">
        <div class="col-12">
          <KpiCards
            :kpi="kpi"
            :loading="kpiLoading"
          />
        </div>
      </div>

      <!-- Row 4: TeamComparisonTable + placeholder -->
      <div class="row g-4 mb-4">
        <div class="col-12 col-lg-6">
          <TeamComparisonTable
            :kpi="kpi"
            :loading="kpiLoading"
          />
        </div>
        <div class="col-12 col-lg-6">
          <!-- Placeholder for next sprint widget -->
          <Card class="widget-placeholder h-100">
            <template #content>
              <div class="widget-placeholder__inner">
                <i class="pi pi-plus widget-placeholder__icon" />
                <p class="widget-placeholder__text">{{ t('common.coming_soon') }}</p>
              </div>
            </template>
          </Card>
        </div>
      </div>

      <!-- Row 5: ActivityFeedList -->
      <div class="row g-4">
        <div class="col-12">
          <ActivityFeedList
            :feed="feed"
            :feed-loading="feedLoading"
            :feed-meta="feedMeta"
            :feed-kind="feedKind"
            :feed-ftm-only="feedFtmOnly"
            @update:feed-kind="setFeedKind"
            @update:feed-ftm-only="setFeedFtmOnly"
            @update:feed-page="setFeedPage"
            @reset-filters="resetFeedFilters"
          />
        </div>
      </div>
    </div>

    <Toast position="top-right" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Toast from 'primevue/toast'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import CabinetHeader from './components/CabinetHeader.vue'
import MonthStepper from './components/MonthStepper.vue'
import KpiCards from './components/KpiCards.vue'
import TeamComparisonTable from './components/TeamComparisonTable.vue'
import ActivityFeedList from './components/ActivityFeedList.vue'
import { useManagerCabinetPage } from './composables/useManagerCabinetPage'

const { t } = useI18n()

const {
  profile,
  profileLoading,
  kpi,
  kpiLoading,
  feed,
  feedLoading,
  feedMeta,
  period,
  feedKind,
  feedFtmOnly,
  viewedUserId,
  canViewOthers,
  memberOptions,
  setPeriod,
  setFeedKind,
  setFeedFtmOnly,
  setFeedPage,
  resetFeedFilters,
  setViewedUser,
} = useManagerCabinetPage()

const memberSelectOptions = computed(() =>
  memberOptions.value.map((u) => ({ label: u.full_name, value: u.id })),
)
</script>

<style lang="scss" scoped>
.manager-cabinet-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.manager-cabinet-page__content {
  padding: $space-6;
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

.manager-cabinet-page__picker-label {
  display: block;
  margin-bottom: $space-2;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;
}

.widget-placeholder {
  background: $surface-card;
  border: 1px dashed $surface-200;
  border-radius: $radius-lg;
  min-height: 200px;

  :deep(.p-card-body) {
    height: 100%;
  }

  :deep(.p-card-content) {
    height: 100%;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.widget-placeholder__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
}

.widget-placeholder__icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.widget-placeholder__text {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}
</style>
