<template>
  <div class="deals-toolbar">
    <!-- Section icon -->
    <span class="deals-toolbar__section-icon">
      <i class="pi pi-briefcase" />
    </span>

    <!-- Title block -->
    <div class="deals-toolbar__title-block">
      <h1 class="deals-toolbar__h1">{{ t('sales.deals.page.title') }}</h1>
      <div class="deals-toolbar__subtitle">{{ subtitle }}</div>
    </div>

    <!-- Spacer -->
    <div class="deals-toolbar__spacer" />

    <!-- Filter button with badge -->
    <div class="deals-toolbar__filter-wrap">
      <Button
        :label="t('sales.deals.page.toolbar.searchAndFilter')"
        icon="pi pi-search"
        severity="secondary"
        outlined
        :class="['deals-toolbar__filter-btn', { 'deals-toolbar__filter-btn--active': filterActive }]"
        @click="emit('openFilter')"
      />
      <span v-if="filterCount > 0" class="deals-toolbar__filter-badge">{{ filterCount }}</span>
    </div>

    <!-- Pipeline switcher -->
    <div class="deals-toolbar__pipeline-wrap">
      <Button
        severity="secondary"
        outlined
        class="deals-toolbar__pipeline-btn"
        :class="{ 'deals-toolbar__pipeline-btn--open': pipelineMenuOpen }"
        @click="emit('openPipelineMenu')"
      >
        <i class="pi pi-sitemap" />
        <span class="deals-toolbar__pipeline-name">{{ pipelineName }}</span>
        <i class="pi pi-chevron-down deals-toolbar__pipeline-chevron" />
      </Button>

      <DealsPipelineMenu
        :open="pipelineMenuOpen"
        :pipelines="pipelines"
        :active-pipeline-id="activePipelineId"
        @set-pipeline="emit('setPipeline', $event)"
        @close="emit('closePipelineMenu')"
      />
    </div>

    <!-- View segment -->
    <div class="deals-toolbar__views">
      <button
        type="button"
        :class="['deals-toolbar__view-btn', { 'deals-toolbar__view-btn--active': activeView === 'kanban' }]"
        :title="t('sales.deals.page.viewBoard')"
        @click="emit('setView', 'kanban')"
      >
        <i class="pi pi-th-large" />
      </button>
      <button
        type="button"
        :class="['deals-toolbar__view-btn', { 'deals-toolbar__view-btn--active': activeView === 'list' }]"
        :title="t('sales.deals.page.viewList')"
        @click="emit('setView', 'list')"
      >
        <i class="pi pi-list" />
      </button>
    </div>

    <!-- More menu -->
    <button
      ref="moreBtnEl"
      type="button"
      class="deals-toolbar__more-btn"
      :title="t('sales.deals.page.toolbar.moreMenu')"
      @click="moreMenu?.toggle($event)"
    >
      <i class="pi pi-ellipsis-v" />
    </button>
    <Menu ref="moreMenu" :model="menuItems" popup />

    <!-- Create button -->
    <Button
      icon="pi pi-plus"
      :label="t('sales.deals.page.create')"
      @click="emit('create')"
    />
  </div>

  <!-- Dedup dialog (opened from MoreMenu → Дубликаты) -->
  <MergeDialog v-model:visible="mergeDialogOpen" />
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import DealsPipelineMenu from './DealsPipelineMenu.vue'
import MergeDialog from '@/components/crm/dedup/MergeDialog.vue'
import type { DealsView } from '@/stores/salesStore'
import type { PipelineDto } from '@/entities/sales'

const props = defineProps<{
  activeView: DealsView
  totalDeals: number
  totalSum: string
  pipelineName: string
  filterActive: boolean
  filterCount: number
  pipelines: PipelineDto[]
  pipelineMenuOpen: boolean
  activePipelineId: number | null
}>()

const emit = defineEmits<{
  openFilter: []
  setView: [view: DealsView]
  create: []
  export: []
  enterBulk: []
  openPipelineMenu: []
  closePipelineMenu: []
  setPipeline: [id: number]
}>()

const { t } = useI18n()

const moreMenu = ref<InstanceType<typeof Menu> | null>(null)
const moreBtnEl = ref<HTMLElement | null>(null)
const mergeDialogOpen = ref(false)

const subtitle = computed(() =>
  `${props.pipelineName} · ${props.totalDeals} сделок · ≈ ${props.totalSum}`,
)

const menuItems = computed(() => [
  {
    label: t('sales.deals.page.menu.bulkActions'),
    icon: 'pi pi-check-square',
    command: () => emit('enterBulk'),
  },
  {
    label: t('sales.deals.page.menu.duplicates'),
    icon: 'pi pi-clone',
    command: () => { mergeDialogOpen.value = true },
  },
  { separator: true },
  {
    label: t('sales.deals.page.menu.import'),
    icon: 'pi pi-download',
    disabled: true,
    class: 'text-muted',
    suffix: t('sales.deals.page.menu.comingSoon'),
  },
  {
    label: t('sales.deals.page.menu.export'),
    icon: 'pi pi-upload',
    command: () => emit('export'),
  },
])
</script>

<style lang="scss" scoped>
.deals-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: 14px $space-5;
  border-bottom: 1px solid var(--p-surface-200);
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;
  position: relative;

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

// Section icon tile
.deals-toolbar__section-icon {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  background: $primary-100;
  border-radius: $radius-md;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    background: rgba(23, 39, 71, 0.35);
  }

  i {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    font-size: 17px; // icon tile — between icon-sm (18px) and font-size-md (16px); no exact token
    color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
    }
  }
}

// Title block
.deals-toolbar__title-block {
  display: flex;
  flex-direction: column;
}

.deals-toolbar__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — between font-size-lg (18px) and font-size-xl (20px); no exact token
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

.deals-toolbar__subtitle {
  font-size: $font-size-xs;
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Spacer
.deals-toolbar__spacer {
  flex: 1;
}

// Filter button
.deals-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
}

.deals-toolbar__filter-btn {
  height: 38px;
}

.deals-toolbar__filter-btn--active {
  background: $primary-100 !important;
  color: $primary-900 !important;
  border-color: $primary-900 !important;

  .app-dark & {
    background: rgba(23, 39, 71, 0.4) !important;
    border-color: var(--p-primary-300) !important;
    color: var(--p-primary-300) !important;
  }
}

.deals-toolbar__filter-badge {
  position: absolute;
  top: -7px;
  right: -7px;
  min-width: 18px;
  height: 18px;
  border-radius: $radius-pill;
  background: $color-warning-badge;
  color: $surface-0;
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
}

// Pipeline switcher
.deals-toolbar__pipeline-wrap {
  position: relative;
  display: inline-flex;
}

.deals-toolbar__pipeline-btn {
  height: 38px;
  display: inline-flex;
  align-items: center;
  gap: $space-1;

  &--open {
    border-color: $primary-900 !important;

    .app-dark & {
      border-color: var(--p-primary-300) !important;
    }
  }
}

.deals-toolbar__pipeline-name {
  font-size: $font-size-sm;
}

.deals-toolbar__pipeline-chevron {
  font-size: $font-size-xs;
  opacity: 0.7;
}

// View segment
.deals-toolbar__views {
  display: inline-flex;
  gap: 2px;
  background: $surface-100;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border-radius: 7px; // view segment pill — between radius-sm (6px) and radius-md (8px); no exact token
  padding: 3px;

  .app-dark & {
    background: var(--p-surface-100);
  }
}

.deals-toolbar__view-btn {
  height: 31px;
  width: 31px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: $radius-sm;
  color: $surface-500;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);
  flex-shrink: 0;

  i {
    font-size: $font-size-sm;
  }

  &:hover {
    background: var(--p-surface-200);
    color: $surface-700;
  }

  .app-dark & {
    color: var(--p-surface-400);

    &:hover {
      background: var(--p-surface-200);
      color: var(--p-surface-50);
    }
  }
}

.deals-toolbar__view-btn--active {
  background: $primary-100;
  color: $primary-900;

  .app-dark & {
    background: rgba(23, 39, 71, 0.45);
    color: var(--p-primary-200);
  }

  &:hover {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      background: rgba(23, 39, 71, 0.45);
      color: var(--p-primary-200);
    }
  }
}

// More button
.deals-toolbar__more-btn {
  height: 31px;
  width: 31px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid $surface-200;
  background: transparent;
  border-radius: $radius-sm;
  color: $surface-500;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);
  flex-shrink: 0;

  i {
    font-size: $font-size-sm;
  }

  &:hover {
    background: var(--p-surface-50);
    border-color: $surface-300;
    color: $surface-700;
  }

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-400);

    &:hover {
      background: var(--p-surface-100);
      border-color: var(--p-surface-300);
      color: var(--p-surface-50);
    }
  }
}
</style>
