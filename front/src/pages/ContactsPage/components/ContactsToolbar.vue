<template>
  <div class="contacts-toolbar">
    <!-- Saved views dropdown -->
    <SavedViewsDropdown
      :model-value="activeView"
      :saved-views="savedViews"
      :default-view-id="defaultViewId"
      :is-loading="savedViewsLoading"
      :is-saving="savedViewsSaving"
      :is-updating="savedViewsUpdating"
      @update:model-value="emit('setView', $event)"
      @save="(name, type, makeDefault) => emit('saveView', name, type, makeDefault)"
      @delete="emit('deleteView', $event)"
      @set-default="emit('setDefaultView', $event)"
      @rename="(id, name) => emit('renameView', id, name)"
    />

    <!-- Record count -->
    <span class="contacts-toolbar__count">
      {{ t('contacts.page.total', { count: total }) }}
    </span>

    <!-- Type switch -->
    <SelectButton
      :model-value="entityType"
      :options="typeOptions"
      option-label="label"
      option-value="value"
      class="contacts-toolbar__type-switch"
      @update:model-value="emit('setEntityType', $event)"
    />

    <!-- Spacer -->
    <div class="contacts-toolbar__spacer" />

    <!-- Search -->
    <IconField class="contacts-toolbar__search-wrap">
      <InputIcon class="pi pi-search" />
      <InputText
        :model-value="search"
        :placeholder="t('contacts.page.filters.search')"
        class="contacts-toolbar__search"
        @update:model-value="emit('search', $event as string)"
      />
    </IconField>

    <!-- Filters button with badge -->
    <div class="contacts-toolbar__filter-wrap">
      <Button
        icon="pi pi-filter"
        :label="t('contacts.page.filters.apply')"
        severity="secondary"
        outlined
        class="contacts-toolbar__filter-btn"
        @click="emit('openFilter')"
      />
      <Badge
        v-if="activeFilterCount > 0"
        :value="activeFilterCount"
        class="contacts-toolbar__filter-badge"
      />
    </div>

    <!-- Density toggle -->
    <Button
      ref="densityBtnRef"
      icon="pi pi-table"
      :title="t('contacts.page.columns.density', 'Плотность')"
      text
      severity="secondary"
      @click="densityMenu?.toggle($event)"
    />
    <Menu ref="densityMenu" :model="densityMenuItems" popup />

    <!-- Column chooser -->
    <Button
      ref="columnsBtnRef"
      icon="pi pi-columns"
      :title="t('contacts.page.columns.choose', 'Выбор колонок')"
      text
      severity="secondary"
      @click="emit('openColumns')"
    />

    <!-- More menu -->
    <Button
      ref="moreBtn"
      icon="pi pi-ellipsis-h"
      text
      severity="secondary"
      :title="t('sales.deals.page.toolbar.moreMenu')"
      @click="moreMenu?.toggle($event)"
    />
    <Menu ref="moreMenu" :model="moreMenuItems" popup />

    <!-- Add button -->
    <Button
      icon="pi pi-plus"
      :label="t('contacts.page.create')"
      @click="emit('create')"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import SelectButton from 'primevue/selectbutton'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Menu from 'primevue/menu'
import SavedViewsDropdown from './SavedViewsDropdown.vue'
import type { SavedView } from '../composables/useSavedViews'
import type { EntityType } from '../composables/useContactsPageData'
import type { ContactsDensity } from '../composables/useContactsView'

const props = defineProps<{
  activeView: string
  savedViews: SavedView[]
  defaultViewId: string | null
  entityType: EntityType
  total: number
  search: string
  activeFilterCount: number
  density: ContactsDensity
  savedViewsLoading?: boolean
  savedViewsSaving?: boolean
  savedViewsUpdating?: boolean
}>()

const emit = defineEmits<{
  setView: [value: string]
  saveView: [name: string, type: 'personal' | 'team', makeDefault: boolean]
  deleteView: [id: string]
  setDefaultView: [id: string]
  renameView: [id: string, name: string]
  setEntityType: [type: EntityType]
  search: [query: string]
  openFilter: []
  openColumns: []
  setDensity: [density: ContactsDensity]
  create: []
  export: []
  openDedup: []
  enterBulk: []
}>()

const { t } = useI18n()

const densityMenu = ref<InstanceType<typeof Menu> | null>(null)
const moreMenu = ref<InstanceType<typeof Menu> | null>(null)

const typeOptions = computed(() => [
  { label: t('contacts.page.typeSwitch.contact'), value: 'contact' as EntityType },
  { label: t('contacts.page.typeSwitch.company'), value: 'company' as EntityType },
])

const densityMenuItems = computed(() => [
  {
    label: t('contacts.page.density.compact', 'Компактный'),
    icon: props.density === 'compact' ? 'pi pi-check' : '',
    command: () => emit('setDensity', 'compact'),
  },
  {
    label: t('contacts.page.density.normal', 'Обычный'),
    icon: props.density === 'normal' ? 'pi pi-check' : '',
    command: () => emit('setDensity', 'normal'),
  },
  {
    label: t('contacts.page.density.comfortable', 'Широкий'),
    icon: props.density === 'comfortable' ? 'pi pi-check' : '',
    command: () => emit('setDensity', 'comfortable'),
  },
])

const moreMenuItems = computed(() => [
  {
    label: t('sales.deals.page.menu.export'),
    icon: 'pi pi-download',
    command: () => emit('export'),
  },
  {
    label: t('contacts.page.create', 'Импорт'),
    icon: 'pi pi-upload',
    command: () => { /* backlog */ },
  },
  { separator: true },
  {
    label: t('crm.contacts_page.savedViews.duplicates'),
    icon: 'pi pi-copy',
    command: () => emit('openDedup'),
  },
  {
    label: t('sales.deals.page.menu.bulkActions'),
    icon: 'pi pi-users',
    command: () => emit('enterBulk'),
  },
])
</script>

<style lang="scss" scoped>
.contacts-toolbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.contacts-toolbar__count {
  font-size: $font-size-sm;
  color: $surface-500;
  white-space: nowrap;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }
}

.contacts-toolbar__type-switch {
  flex-shrink: 0;
}

.contacts-toolbar__spacer {
  flex: 1;
  min-width: $space-2;
}

.contacts-toolbar__search-wrap {
  min-width: 200px;
}

.contacts-toolbar__search {
  width: 100%;
}

.contacts-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
}

.contacts-toolbar__filter-btn {
  flex-shrink: 0;
}

.contacts-toolbar__filter-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  font-size: 10px;
  min-width: 18px;
  height: 18px;
}
</style>
