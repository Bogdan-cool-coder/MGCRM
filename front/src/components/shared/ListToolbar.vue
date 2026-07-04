<template>
  <div class="list-toolbar">
    <!-- Section icon tile 38×38 -->
    <span class="list-toolbar__section-icon">
      <i :class="['pi', icon]" />
    </span>

    <!-- Title block -->
    <div class="list-toolbar__title-block">
      <h1 class="list-toolbar__h1">{{ title }}</h1>
      <div v-if="subtitle" class="list-toolbar__subtitle">{{ subtitle }}</div>
    </div>

    <!-- Segmented control (optional) -->
    <slot name="segmented" />

    <!-- Spacer -->
    <div class="list-toolbar__spacer" />

    <!-- Filter trigger / extras (optional) -->
    <slot name="filter" />

    <!-- Right-side actions (⋮, Create, …) -->
    <div v-if="$slots.actions" class="list-toolbar__actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * Shared list/hub toolbar — Periphery Uplift §0 canon (DealsToolbar/ContactsToolbar
 * shape). Single component reused across the onboarding module (and any other
 * non-pilot list screen) instead of duplicating ~150 lines of toolbar SCSS.
 *
 * Layout: icon-tile 38×38 → title + subtitle-counter → [segmented] → spacer →
 * [filter] → [actions]. Border-bottom + $surface-card background per canon.
 */
defineProps<{
  /** PrimeIcons class without the `pi ` prefix, e.g. `pi-book`. */
  icon: string
  title: string
  /** Muted counter line, e.g. «12 курсов». */
  subtitle?: string
}>()
</script>

<style lang="scss" scoped>
.list-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-4 $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

// Section icon tile — same token set as DealsToolbar__section-icon
.list-toolbar__section-icon {
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

.list-toolbar__title-block {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.list-toolbar__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — between font-size-lg (18px) and font-size-xl (20px); no exact token
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.list-toolbar__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 12px; // spec §2 = 12px; $font-size-xs = 10.5px (no exact token for 12px)
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.list-toolbar__spacer {
  flex: 1;
}

.list-toolbar__actions {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}
</style>
