<template>
  <div class="manager-cabinet-page">
    <CabinetToolbar
      :subtitle="toolbarSubtitle"
      :active-tab="activeTab"
      :can-view-others="canViewOthers"
      :viewed-user-id="viewedUserId"
      :member-select-options="memberSelectOptions"
      :overview-period="period"
      :overview-month-options="overviewMonthOptions"
      :motivation-month-value="motivationMonthValue"
      :motivation-month-options="motivationMonthOptions"
      @update:active-tab="setActiveTab"
      @update:viewed-user="(v) => setViewedUser(v)"
      @update:overview-period="setPeriod"
      @update:motivation-month="setMotivationMonth"
    />

    <div class="manager-cabinet-page__content">
      <!-- Motivation card tab -->
      <MotivationTab
        v-if="activeTab === 'motivation'"
        :viewed-user-id="viewedUserId"
      />

      <!-- Dashboard overview tab -->
      <div v-else class="manager-cabinet-page__overview">
        <ResultsHero
          :kpi="kpi"
          :loading="kpiLoading"
          :period-label="periodLabel"
        />
        <ActivityFeed
          :feed="feed"
          :feed-loading="feedLoading"
          :feed-meta="feedMeta"
          :feed-kind="feedKind"
          @update:feed-kind="setFeedKind"
          @update:feed-page="setFeedPage"
          @reset-filters="resetFeedFilters"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CabinetToolbar from './components/CabinetToolbar.vue'
import ResultsHero from './components/ResultsHero.vue'
import ActivityFeed from './components/ActivityFeed.vue'
import MotivationTab from './components/motivation/MotivationTab.vue'
import { useManagerCabinetPage } from './composables/useManagerCabinetPage'
import { useMotivationMonths } from './composables/useMotivationMonths'

const route = useRoute()
const router = useRouter()

// ─── Tab strip (overview | motivation) ────────────────────────────────────────
type CabinetTab = 'overview' | 'motivation'

const activeTab = computed<CabinetTab>(() =>
  route.query.tab === 'motivation' ? 'motivation' : 'overview',
)

const setActiveTab = (tab: CabinetTab): void => {
  const query = { ...route.query }
  if (tab === 'overview') {
    delete query.tab
  } else {
    query.tab = tab
  }
  void router.replace({ query })
}

const {
  profile,
  kpi,
  kpiLoading,
  feed,
  feedLoading,
  feedMeta,
  period,
  overviewMonthOptions,
  feedKind,
  viewedUserId,
  canViewOthers,
  memberOptions,
  setPeriod,
  setFeedKind,
  setFeedPage,
  resetFeedFilters,
  setViewedUser,
} = useManagerCabinetPage()

// Motivation month state lives in the URL (?year=&month=); useMotivationTab reads
// it inside MotivationTab. The toolbar just needs the options + selected value +
// a setter — computed here so no duplicate card fetch/poll is spawned.
const { monthOptions: motivationMonthOptions, selectedValue: motivationMonthValue, setMonthByValue: setMotivationMonth } =
  useMotivationMonths()

const memberSelectOptions = computed(() =>
  memberOptions.value.map((u) => ({ label: u.full_name, value: u.id })),
)

const toolbarSubtitle = computed<string | null>(() => {
  const p = profile.value
  if (!p) return null
  return p.department_name ? `${p.full_name} · ${p.department_name}` : p.full_name
})

const periodLabel = computed<string>(() => kpi.value?.meta.period.label ?? '')
</script>

<style lang="scss" scoped>
.manager-cabinet-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.manager-cabinet-page__content {
  padding: $space-5 $space-5;
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

.manager-cabinet-page__overview {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}
</style>
