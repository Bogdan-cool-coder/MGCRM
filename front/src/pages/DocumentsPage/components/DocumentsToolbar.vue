<template>
  <div class="documents-toolbar">
    <!-- Section icon -->
    <span class="documents-toolbar__section-icon">
      <i class="pi pi-file-edit" />
    </span>

    <!-- Title block -->
    <div class="documents-toolbar__title-block">
      <h1 class="documents-toolbar__h1">{{ t('documents.list.title') }}</h1>
      <div class="documents-toolbar__subtitle">
        {{ t('documents.list.subtitle', { count: total }) }}
      </div>
    </div>

    <!-- Spacer -->
    <div class="documents-toolbar__spacer" />

    <!-- Filter trigger with badge (pattern Б) -->
    <div class="documents-toolbar__filter-wrap">
      <button
        type="button"
        :class="['documents-toolbar__filter-btn', { 'documents-toolbar__filter-btn--active': filterCount > 0 }]"
        @click="emit('openFilter', $event)"
      >
        <i class="pi pi-filter" />
        <span>{{ t('documents.list.filtersBtn') }}</span>
      </button>
      <span v-if="filterCount > 0" class="documents-toolbar__filter-badge">{{ filterCount }}</span>
    </div>

    <!-- Create button -->
    <Button
      v-if="canCreate"
      icon="pi pi-plus"
      :label="t('documents.list.create')"
      @click="emit('create')"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

defineProps<{
  total: number
  filterCount: number
  canCreate: boolean
}>()

const emit = defineEmits<{
  openFilter: [event: Event]
  create: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.documents-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 14px 0 $space-4; // toolbar vertical rhythm — matches DealsToolbar 14px; no exact token
  flex-wrap: wrap;
}

// Section icon tile
.documents-toolbar__section-icon {
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
    font-size: 17px; // icon tile — canon DealsToolbar; no exact token between icon-sm/md
    color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
    }
  }
}

// Title block
.documents-toolbar__title-block {
  display: flex;
  flex-direction: column;
}

.documents-toolbar__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — canon DealsToolbar; no exact token between lg/xl
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.documents-toolbar__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 12px; // subtitle-counter — canon 12px; $font-size-xs is 10.5px
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Spacer
.documents-toolbar__spacer {
  flex: 1;
}

// Filter trigger
.documents-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
}

.documents-toolbar__filter-btn {
  height: 38px;
  box-sizing: border-box;
  padding: 0 14px;
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  background: transparent;
  color: $surface-600;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  white-space: nowrap;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);

  i {
    font-size: $font-size-sm;
  }

  &:hover {
    background: var(--p-surface-50);
    border-color: $surface-300;
  }

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);

    &:hover {
      background: var(--p-surface-100);
      border-color: var(--p-surface-400);
    }
  }
}

.documents-toolbar__filter-btn--active {
  background: $primary-100 !important;
  color: $primary-900 !important;
  border-color: $primary-900 !important;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 40%, transparent) !important;
    border-color: var(--p-primary-300) !important;
    color: var(--p-primary-300) !important;
  }
}

.documents-toolbar__filter-badge {
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
</style>
