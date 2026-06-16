<template>
  <div class="deals-toolbar">
    <!-- Search & Filter button -->
    <Button
      icon="pi pi-search"
      :label="t('sales.deals.page.toolbar.searchAndFilter')"
      severity="secondary"
      outlined
      class="deals-toolbar__filter-btn"
      @click="emit('openFilter')"
    />

    <!-- Summary -->
    <span class="deals-toolbar__summary">
      {{ summary }}
    </span>

    <!-- Spacer -->
    <div class="deals-toolbar__spacer" />

    <!-- View switcher -->
    <div class="deals-toolbar__views">
      <Button
        icon="pi pi-th-large"
        :class="['deals-toolbar__view-btn', { 'deals-toolbar__view-btn--active': activeView === 'kanban' }]"
        :severity="activeView === 'kanban' ? 'primary' : 'secondary'"
        text
        :title="t('sales.deals.page.viewBoard')"
        @click="emit('setView', 'kanban')"
      />
      <Button
        icon="pi pi-list"
        :class="['deals-toolbar__view-btn', { 'deals-toolbar__view-btn--active': activeView === 'list' }]"
        :severity="activeView === 'list' ? 'primary' : 'secondary'"
        text
        :title="t('sales.deals.page.viewList')"
        @click="emit('setView', 'list')"
      />
      <Button
        icon="pi pi-check-square"
        :class="['deals-toolbar__view-btn', { 'deals-toolbar__view-btn--active': activeView === 'tasks' }]"
        :severity="activeView === 'tasks' ? 'primary' : 'secondary'"
        text
        :title="t('sales.deals.page.viewTasks')"
        @click="emit('setView', 'tasks')"
      />
    </div>

    <!-- More menu -->
    <Button
      ref="moreBtn"
      icon="pi pi-ellipsis-h"
      text
      severity="secondary"
      :title="t('sales.deals.page.toolbar.moreMenu')"
      class="deals-toolbar__more-btn"
      @click="moreMenu?.toggle($event)"
    />
    <Menu ref="moreMenu" :model="menuItems" popup />

    <!-- New deal button -->
    <Button
      icon="pi pi-plus"
      :label="t('sales.deals.page.create')"
      @click="emit('create')"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { DealsView, BoardSort } from '@/stores/salesStore'

const props = defineProps<{
  activeView: DealsView
  totalDeals: number
  totalSum: string
  activeSort: BoardSort
}>()

const emit = defineEmits<{
  openFilter: []
  setView: [view: DealsView]
  create: []
  export: []
  enterBulk: []
  setSort: [sort: BoardSort]
}>()

const { t } = useI18n()

const moreMenu = ref<InstanceType<typeof Menu> | null>(null)

const summary = computed(() =>
  t('sales.deals.page.summary', { count: props.totalDeals, total: props.totalSum }),
)

const menuItems = computed(() => [
  {
    label: t('sales.deals.page.menu.import'),
    icon: 'pi pi-upload',
    command: () => { /* backlog */ },
  },
  {
    label: t('sales.deals.page.menu.export'),
    icon: 'pi pi-download',
    command: () => emit('export'),
  },
  { separator: true },
  {
    label: t('sales.deals.page.menu.duplicates'),
    icon: 'pi pi-copy',
    command: () => { /* backlog */ },
  },
  {
    label: t('sales.deals.page.menu.bulkActions'),
    icon: 'pi pi-users',
    command: () => emit('enterBulk'),
  },
  { separator: true },
  {
    label: t('sales.deals.page.menu.sort'),
    icon: 'pi pi-sort-alt',
    items: [
      {
        label: t('sales.deals.page.menu.sortCreatedAt'),
        icon: props.activeSort === 'created_at_desc' ? 'pi pi-check' : '',
        command: () => emit('setSort', 'created_at_desc'),
      },
      {
        label: t('sales.deals.page.menu.sortTitle'),
        icon: props.activeSort === 'title_asc' ? 'pi pi-check' : '',
        command: () => emit('setSort', 'title_asc'),
      },
      {
        label: t('sales.deals.page.menu.sortAmount'),
        icon: props.activeSort === 'amount_desc' ? 'pi pi-check' : '',
        command: () => emit('setSort', 'amount_desc'),
      },
      {
        label: t('sales.deals.page.menu.sortActivity'),
        icon: props.activeSort === 'last_activity_desc' ? 'pi pi-check' : '',
        command: () => emit('setSort', 'last_activity_desc'),
      },
    ],
  },
])
</script>

<style lang="scss" scoped>
.deals-toolbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.deals-toolbar__filter-btn {
  flex-shrink: 0;
}

.deals-toolbar__summary {
  font-size: $font-size-sm;
  color: $surface-500;
  white-space: nowrap;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }
}

.deals-toolbar__spacer {
  flex: 1;
}

.deals-toolbar__views {
  display: flex;
  align-items: center;
  gap: 2px;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: 2px;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deals-toolbar__view-btn {
  &--active {
    background: var(--p-primary-50) !important;

    :global(.app-dark) & {
      background: rgba(23, 39, 71, 0.4) !important;
    }
  }
}

.deals-toolbar__more-btn {
  flex-shrink: 0;
}
</style>
