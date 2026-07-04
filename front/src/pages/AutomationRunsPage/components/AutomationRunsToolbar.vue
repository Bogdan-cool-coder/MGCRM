<template>
  <!--
    Automation-runs list toolbar (Periphery uplift §D.1) — one row matching the
    core 2.0 canon (DealsToolbar / HubToolbar): 38×38 icon tile + title block with
    subtitle counter → spacer → filter-trigger with active-count badge → "Dry-run"
    secondary. Presentational only: props down, emits up. All runs logic stays in
    the page composable.
  -->
  <div class="automation-runs-toolbar">
    <!-- Section icon tile -->
    <span class="automation-runs-toolbar__icon-tile">
      <i class="pi pi-clock" aria-hidden="true" />
    </span>

    <!-- Title block -->
    <div class="automation-runs-toolbar__title-block">
      <h1 class="automation-runs-toolbar__h1">{{ t('automation.runs.pageTitle') }}</h1>
      <div class="automation-runs-toolbar__subtitle">
        {{ t('automation.runs.showing', { n: loadedCount }) }}
      </div>
    </div>

    <!-- Spacer -->
    <div class="automation-runs-toolbar__spacer" />

    <!-- Filter trigger with badge -->
    <div class="automation-runs-toolbar__filter-wrap">
      <button
        type="button"
        :class="[
          'automation-runs-toolbar__filter-btn',
          { 'automation-runs-toolbar__filter-btn--active': filterOpen },
        ]"
        @click="emit('toggleFilter')"
      >
        <i class="pi pi-filter" />
        <span>{{ t('common.filter') }}</span>
      </button>
      <span v-if="filterCount > 0" class="automation-runs-toolbar__filter-badge">{{ filterCount }}</span>
    </div>

    <!-- Dry-run -->
    <Button
      :label="t('automation.dryrun.button')"
      icon="pi pi-play"
      severity="secondary"
      outlined
      :disabled="dryRunDisabled"
      @click="emit('dryRun')"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

defineProps<{
  loadedCount: number
  filterOpen: boolean
  filterCount: number
  dryRunDisabled: boolean
}>()

const emit = defineEmits<{
  toggleFilter: []
  dryRun: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.automation-runs-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 14px $space-5; // toolbar row height matches DealsToolbar (14px vertical)
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
.automation-runs-toolbar__icon-tile {
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
.automation-runs-toolbar__title-block {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.automation-runs-toolbar__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — between font-size-lg (18px) and font-size-xl (20px); no exact token
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.automation-runs-toolbar__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 12px; // spec §2 = 12px; $font-size-xs = 10.5px (no exact token for 12px)
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Spacer
.automation-runs-toolbar__spacer {
  flex: 1;
}

// Filter trigger
.automation-runs-toolbar__filter-wrap {
  position: relative;
  display: inline-flex;
}

.automation-runs-toolbar__filter-btn {
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

.automation-runs-toolbar__filter-btn--active {
  background: $primary-100 !important;
  color: $primary-900 !important;
  border-color: $primary-900 !important;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 40%, transparent) !important;
    border-color: var(--p-primary-300) !important;
    color: var(--p-primary-300) !important;
  }
}

.automation-runs-toolbar__filter-badge {
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
