<template>
  <div class="contacts-toolbar">
    <!-- Icon + title -->
    <i
      :class="entityType === 'company' ? 'pi pi-building' : 'pi pi-users'"
      class="contacts-toolbar__entity-icon"
    />
    <h1 class="contacts-toolbar__title">{{ t('contacts.page.header.title') }}</h1>

    <!-- Entity type switch — custom segmented control (§2.1/§2.3 spec) -->
    <div
      role="tablist"
      class="contacts-toolbar__type-switch"
      :aria-label="t('contacts.page.header.entitySwitch')"
    >
      <button
        v-for="opt in typeOptions"
        :key="opt.value"
        role="tab"
        :aria-selected="entityType === opt.value"
        :class="['contacts-toolbar__type-btn', { 'contacts-toolbar__type-btn--active': entityType === opt.value }]"
        type="button"
        @click="emit('setEntityType', opt.value)"
        @keydown.right.prevent="emit('setEntityType', 'contact')"
        @keydown.left.prevent="emit('setEntityType', 'company')"
      >{{ opt.label }}</button>
    </div>

    <!-- Search — manual icon -->
    <div class="contacts-toolbar__search-wrap">
      <i class="pi pi-search contacts-toolbar__search-icon" />
      <InputText
        :model-value="search"
        :placeholder="searchPlaceholder"
        class="contacts-toolbar__search"
        @update:model-value="emit('search', $event as string)"
      />
    </div>

    <!-- Filters button with orange badge -->
    <div class="contacts-toolbar__filter-wrap">
      <Button
        icon="pi pi-filter"
        :label="t('contacts.page.filters.openPanel')"
        severity="secondary"
        outlined
        class="contacts-toolbar__filter-btn"
        @click="emit('openFilter')"
      />
      <span
        v-if="activeFilterCount > 0"
        class="contacts-toolbar__filter-badge"
      >{{ activeFilterCount }}</span>
    </div>

    <!-- Right group: More + Create -->
    <div class="contacts-toolbar__actions">
      <!-- More menu -->
      <Button
        ref="moreBtn"
        icon="pi pi-ellipsis-h"
        outlined
        severity="secondary"
        class="contacts-toolbar__more-btn"
        :title="t('contacts.page.menu.more', 'Ещё')"
        @click="moreMenu?.toggle($event)"
      />
      <Menu ref="moreMenu" :model="moreMenuItems" popup />

      <!-- Create button -->
      <Button
        icon="pi pi-plus"
        :label="createLabel"
        class="contacts-toolbar__create-btn"
        @click="emit('create')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Menu from 'primevue/menu'
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

const moreMenu = ref<InstanceType<typeof Menu> | null>(null)

const typeOptions = computed(() => [
  { label: t('contacts.page.header.switchCompany'), value: 'company' as EntityType },
  { label: t('contacts.page.header.switchContact'), value: 'contact' as EntityType },
])

const searchPlaceholder = computed(() =>
  props.entityType === 'company'
    ? t('contacts.page.search.placeholder.company')
    : t('contacts.page.search.placeholder.contact'),
)

const createLabel = computed(() =>
  props.entityType === 'company'
    ? t('contacts.page.createCompany')
    : t('contacts.page.createContact'),
)

const moreMenuItems = computed(() => [
  {
    label: t('contacts.page.menu.dedup'),
    icon: 'pi pi-copy',
    command: () => emit('openDedup'),
  },
  {
    label: t('contacts.page.menu.import'),
    icon: 'pi pi-upload',
    command: () => { /* backlog */ },
  },
  {
    label: t('contacts.page.menu.export'),
    icon: 'pi pi-download',
    command: () => emit('export'),
  },
  {
    label: t('contacts.page.menu.columns'),
    icon: 'pi pi-columns',
    command: () => emit('openColumns'),
  },
  { separator: true },
  {
    label: t('contacts.page.menu.bulk'),
    icon: 'pi pi-users',
    command: () => emit('enterBulk'),
  },
])
</script>

<style lang="scss" scoped>
.contacts-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-4 $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-700);
  }
}

.contacts-toolbar__entity-icon {
  font-size: $font-size-icon-sm; // ~20px
  color: $primary-900;
  flex-shrink: 0;
}

.contacts-toolbar__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  margin: 0;
  white-space: nowrap;
}

.contacts-toolbar__type-switch {
  // Track container
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  height: 38px;
  box-sizing: border-box;
  border-radius: $radius-md;
  background: var(--p-surface-100);
  flex-shrink: 0;
  align-items: stretch;
}

.contacts-toolbar__type-btn {
  flex: 1 0 auto;
  padding: 0 $space-4;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  border: 0;
  border-radius: $radius-sm;
  cursor: pointer;
  background: transparent;
  color: var(--p-text-muted-color);
  line-height: 1;
  box-sizing: border-box;
  transition: background 0.15s, color 0.15s, box-shadow 0.15s;
  white-space: nowrap;

  &:hover:not(.contacts-toolbar__type-btn--active) {
    color: var(--p-text-color);
  }
}

// Active chip uses a dedicated single BEM modifier (NOT a compound `&.is-active`):
// the Vue scoped compiler reliably emits `.app-dark .…--active[data-v]` for a
// single-class modifier with nested `.app-dark &`; a compound parent gets
// dropped (it mis-compiled to a bare global `.app-dark{}`). Same pattern as
// add-channel-dialog__type-btn--active. Light: white + navy. Dark: a surface
// lighter than the #444547 track + light text → reads in dark.
.contacts-toolbar__type-btn--active {
  background: var(--p-surface-0);
  color: $primary-900;
  box-shadow: $shadow-sm;

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-text-color);
  }
}

.contacts-toolbar__search-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.contacts-toolbar__search-icon {
  position: absolute;
  left: 11px;
  font-size: $font-size-xs;
  color: $surface-400;
  pointer-events: none;
  z-index: 1;
}

.contacts-toolbar__search {
  width: 240px;
  height: 38px;
  padding: $space-2 $space-3 $space-2 32px;
  font-size: $font-size-base;
  box-sizing: border-box;
}

.contacts-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
}

.contacts-toolbar__filter-btn {
  height: 38px;
  flex-shrink: 0;
}

.contacts-toolbar__filter-badge {
  position: absolute;
  top: -7px;
  right: -7px;
  min-width: 18px;
  height: 18px;
  border-radius: $radius-badge;
  background: $orange-500;
  color: $surface-0;
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  box-sizing: border-box;
  pointer-events: none;
}

.contacts-toolbar__actions {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}

.contacts-toolbar__more-btn {
  height: 31px;
  padding: 0 $space-2;
  box-sizing: border-box;
}

.contacts-toolbar__create-btn {
  height: 38px;
  box-sizing: border-box;
}
</style>
