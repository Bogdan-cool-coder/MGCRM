<template>
  <div class="products-toolbar">
    <!-- Section icon tile -->
    <span class="products-toolbar__section-icon">
      <i class="pi pi-box" />
    </span>

    <!-- Title block -->
    <div class="products-toolbar__title-block">
      <h1 class="products-toolbar__h1">{{ t('catalog.products.page.title') }}</h1>
      <div class="products-toolbar__subtitle">{{ t('common.total', { count: total }) }}</div>
    </div>

    <!-- Search pill (kept visible — [ОВ-A1] default) -->
    <div class="products-toolbar__search-wrap">
      <i class="pi pi-search products-toolbar__search-icon" />
      <InputText
        :model-value="search"
        :placeholder="t('catalog.products.page.filters.search')"
        class="products-toolbar__search"
        @update:model-value="emit('search', ($event as string) ?? '')"
      />
    </div>

    <!-- Spacer -->
    <div class="products-toolbar__spacer" />

    <!-- Filter trigger with orange badge (pattern Б) -->
    <div class="products-toolbar__filter-wrap">
      <Button
        icon="pi pi-filter"
        :label="t('catalog.products.page.toolbar.filters')"
        severity="secondary"
        outlined
        class="products-toolbar__filter-btn"
        @click="emit('openFilter', $event)"
      />
      <span
        v-if="activeFilterCount > 0"
        class="products-toolbar__filter-badge"
      >{{ activeFilterCount }}</span>
    </div>

    <!-- Right group: Import ⋮ + Create (canWrite only) -->
    <template v-if="canWrite">
      <button
        type="button"
        class="products-toolbar__more-btn"
        :title="t('catalog.products.page.toolbar.moreMenu')"
        @click="moreMenu?.toggle($event)"
      >
        <i class="pi pi-ellipsis-v" />
      </button>
      <Menu ref="moreMenu" :model="moreMenuItems" popup />

      <Button
        icon="pi pi-plus"
        :label="t('catalog.products.page.create')"
        class="products-toolbar__create-btn"
        @click="emit('create')"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Menu from 'primevue/menu'

defineProps<{
  total: number
  search: string
  activeFilterCount: number
  canWrite: boolean
}>()

const emit = defineEmits<{
  search: [query: string]
  openFilter: [event: Event]
  create: []
  import: []
  downloadTemplate: []
}>()

const { t } = useI18n()

const moreMenu = ref<InstanceType<typeof Menu> | null>(null)

const moreMenuItems = computed(() => [
  {
    label: t('catalog.products.page.importMenu.upload'),
    icon: 'pi pi-upload',
    command: () => emit('import'),
  },
  {
    label: t('catalog.products.page.importMenu.template'),
    icon: 'pi pi-download',
    command: () => emit('downloadTemplate'),
  },
])
</script>

<style lang="scss" scoped>
.products-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: 14px $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

// Section icon tile — 38×38, canon
.products-toolbar__section-icon {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  background: $primary-100;
  border-radius: $radius-md;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 35%, transparent);
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
.products-toolbar__title-block {
  display: flex;
  flex-direction: column;
}

.products-toolbar__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — between font-size-lg (18px) and font-size-xl (20px); no exact token
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-900); // dark surface-900 = light navy text on dark bg
  }
}

.products-toolbar__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 12px; // spec = 12px; $font-size-xs = 10.5px (no exact token for 12px)
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Search pill
.products-toolbar__search-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}

.products-toolbar__search-icon {
  position: absolute;
  left: 11px;
  font-size: $font-size-xs;
  color: $surface-400;
  pointer-events: none;
  z-index: 1;
}

.products-toolbar__search {
  width: 240px;
  height: 38px;
  padding: $space-2 $space-3 $space-2 32px;
  font-size: $font-size-base;
  box-sizing: border-box;
}

// Spacer
.products-toolbar__spacer {
  flex: 1;
}

// Filter trigger + badge
.products-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
}

.products-toolbar__filter-btn {
  height: 38px;
  flex-shrink: 0;
}

.products-toolbar__filter-badge {
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

// More button — same token set as DealsToolbar/ContactsToolbar
.products-toolbar__more-btn {
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

.products-toolbar__create-btn {
  height: 38px;
  box-sizing: border-box;
}
</style>
