<template>
  <div class="dashboard-detail-page">
    <LoadingState v-if="loading" />

    <div v-else-if="dashboard" class="dashboard-card">
      <div class="dashboard-header">
        <div class="header-left">
          <Button icon="pi pi-arrow-left" :label="t('back')" @click="goBack" />
          <h1 class="dashboard-title">{{ localizedTitle }}</h1>
          <Tag v-if="dashboard.isSystem" :value="t('systemBadge')" severity="info" />
        </div>

        <div class="header-right">
          <PeriodPicker v-model="period" />

          <Button
            v-if="dashboard.isSystem && canCloneSystem"
            icon="pi pi-clone"
            :label="t('makeItMine')"
            :loading="isCloning"
            severity="secondary"
            @click="cloneDashboard"
          />

          <Button
            v-if="isEditable"
            icon="pi pi-plus"
            :label="t('addWidget')"
            @click="openLibrary"
          />

          <DashboardActionsMenu
            :dashboard="dashboard"
            :can-add-widget="isEditable"
            @add-widget="openLibrary"
            @published-changed="onPublishedChanged"
          />
        </div>
      </div>

      <div class="dashboard-body">
        <EmptyState v-if="!hasWidgets" :message="t('emptyDashboard')" icon="pi pi-th-large" />

        <GridLayout
          v-else
          :layout="gridLayout"
          :col-num="12"
          :row-height="60"
          :is-draggable="isEditable"
          :is-resizable="isEditable"
          :margin="[12, 12]"
          :use-css-transforms="true"
          @layout-updated="persistLayout"
        >
          <template #item="{ item }">
            <WidgetChartCard
              v-if="widgetById(toId(item.i))"
              :title="widgetTitle(toId(item.i))"
              :chart-type="widgetChartType(toId(item.i))"
              :config="widgetConfig(toId(item.i))"
              :data="widgetData(toId(item.i))"
              :visible="isWidgetVisible(toId(item.i))"
              :is-loading="isLoadingData"
              :editable="isEditable"
              @edit="onEditWidget(toId(item.i))"
              @detach="onDetachWidget(toId(item.i))"
              @toggle-visibility="(v) => toggleWidgetVisibility(toId(item.i), v)"
            />
          </template>
        </GridLayout>
      </div>
    </div>

    <EmptyState v-else :message="t('notFound')" icon="pi pi-exclamation-triangle" />

    <WidgetLibraryModal
      v-model:visible="isLibraryOpen"
      :attached-widget-ids="attachedWidgetIds"
      @pick="onPickWidget"
      @create-widget="onCreateWidget"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { GridLayout } from 'grid-layout-plus'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import PeriodPicker from './components/PeriodPicker.vue'
import WidgetChartCard from './components/WidgetChartCard.vue'
import WidgetLibraryModal from './components/WidgetLibraryModal.vue'
import DashboardActionsMenu from './components/DashboardActionsMenu.vue'
import { useDashboardPage } from './composables/useDashboardPage'
import { getLocalizedText } from '@/utils/localization'
import { canManageDashboards } from '@/shared/auth/capabilities'
import { useUserStore } from '@/stores/user'
import type { WidgetChartType, WidgetConfigDto } from '@/entities/widget'
import type { DashboardWidget } from '@/entities/dashboard'

const userStore = useUserStore()

const {
  t,
  locale,
  dashboard,
  loading,
  isLoadingData,
  period,
  localizedTitle,
  gridLayout,
  persistLayout,
  isLibraryOpen,
  openLibrary,
  closeLibrary,
  attachWidget,
  toggleWidgetVisibility,
  widgetData,
  hasWidgets,
  isEditable,
  isCloning,
  goBack,
  cloneDashboard,
  openWidgetGeneration,
  editWidget,
  detachWidget,
} = useDashboardPage()

const canCloneSystem = computed(() => canManageDashboards(userStore.getUserRole))

const attachedWidgetIds = computed(() => dashboard.value?.widgets.map((w) => w.id) ?? [])

/** grid-layout-plus types `item.i` as `string | number`; ours is always a widget id. */
const toId = (i: string | number): number => (typeof i === 'number' ? i : Number(i))

const widgetById = (id: number): DashboardWidget | null =>
  dashboard.value?.widgets.find((w) => w.id === id) ?? null

const isWidgetVisible = (id: number): boolean =>
  gridLayout.value.find((item) => item.i === id)?.visible ?? true

const widgetTitle = (id: number): string => {
  const w = widgetById(id)
  return w ? getLocalizedText(w.name, locale.value) : ''
}

const widgetChartType = (id: number): WidgetChartType => {
  const w = widgetById(id)
  const type = w?.config?.chart?.type
  return (type ?? 'bar') as WidgetChartType
}

const widgetConfig = (id: number): WidgetConfigDto | null => widgetById(id)?.config ?? null

const onEditWidget = (id: number) => {
  const w = widgetById(id)
  if (w) void editWidget(w)
}

const onDetachWidget = (id: number) => {
  const w = widgetById(id)
  if (w) void detachWidget(w)
}

const onPickWidget = async (widgetId: number) => {
  await attachWidget(widgetId)
}

const onCreateWidget = () => {
  closeLibrary()
  openWidgetGeneration()
}

/** Reflect a publish/unpublish toggle from the actions menu in local state. */
const onPublishedChanged = (isPublished: boolean) => {
  if (dashboard.value) dashboard.value.isPublished = isPublished
}
</script>

<style lang="scss" scoped>
.dashboard-detail-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;
  // The dashboard grid (grid-layout-plus) self-sizes to the full height of all
  // its rows, so its content frequently exceeds the viewport. This page is the
  // single scroll container: the card/body below grow to that content height
  // (`flex: 1 0 auto`, no inner `overflow`), and the page scrolls vertically.
  // Previously the body used `overflow: auto` inside an `overflow: hidden` card,
  // which clamped the grid and clipped the bottom rows with no reachable scroll.
  overflow-y: auto;
  overflow-x: hidden;
  // Match ReportPage: push the draggable Toolbox below the page header strip.
  --toolbox-top-offset: 5rem;
  --toolbox-top-offset-mobile: 4.5rem;

  .dashboard-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    // Grow to fit the grid; never clamp/clip its content.
    flex: 1 0 auto;
    min-height: 0;
  }

  .dashboard-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    flex-shrink: 0;

    .header-left {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      min-width: 0;

      .dashboard-title {
        margin: 0;
        font-size: $font-size-xl;
        font-weight: $font-weight-semibold;
        color: $surface-900;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
  }

  .dashboard-body {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid $surface-200;
    // Grow to the grid's full height; the page (`.dashboard-detail-page`) owns
    // the scroll. No inner `overflow` here — that is what clipped the grid.
    flex: 1 0 auto;
    min-height: 0;

    :deep(.empty-state) {
      // Keep the empty state filling the visible area (page is `height: 100%`).
      min-height: 60vh;
    }
  }
}
</style>
