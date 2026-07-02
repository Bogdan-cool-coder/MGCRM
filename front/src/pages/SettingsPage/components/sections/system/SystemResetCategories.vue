<template>
  <div class="reset-cats">
    <!-- Master row: select-all with indeterminate state -->
    <div class="reset-cats__master" @click="reset.toggleAll()">
      <Checkbox
        :model-value="reset.allSelected.value"
        :indeterminate="reset.someSelected.value"
        binary
        :aria-label="t('settings.system.reset.selectAll')"
        @click.stop="reset.toggleAll()"
      />
      <span class="reset-cats__master-label">{{ t('settings.system.reset.selectAll') }}</span>
      <span class="reset-cats__master-count">
        {{ t('settings.system.reset.selectedCount', { n: reset.selectedCount.value, total: reset.totalCount.value }) }}
      </span>
    </div>

    <!-- Category rows -->
    <div
      v-for="cat in reset.categories.value"
      :key="cat.key"
      class="reset-cat-row"
      :class="{ 'reset-cat-row--selected': reset.isSelected(cat.key) }"
      @click="reset.toggle(cat.key)"
    >
      <Checkbox
        :model-value="reset.isSelected(cat.key)"
        binary
        :aria-label="categoryName(cat.key)"
        @click.stop="reset.toggle(cat.key)"
      />
      <span class="reset-cat-row__icon-wrap">
        <i :class="['pi', CATEGORY_ICON[cat.key] ?? 'pi-database', 'reset-cat-row__icon']" aria-hidden="true" />
      </span>
      <div class="reset-cat-row__body">
        <div class="reset-cat-row__name">{{ categoryName(cat.key) }}</div>
        <div class="reset-cat-row__desc">{{ categoryDesc(cat.key) }}</div>
      </div>
      <Tag
        severity="secondary"
        :value="t('settings.system.reset.recordsTag', { n: formatNumber(cat.count) })"
        class="reset-cat-row__count"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import type { useSystemResetSelective } from '../../../composables/useSystemResetSelective'

const { t, n: formatNumber } = useI18n()

const props = defineProps<{
  reset: ReturnType<typeof useSystemResetSelective>
}>()

// Local re-export so the template reads cleanly.
const reset = props.reset

/** category key → icon (design system-section.jsx S_CATS). */
const CATEGORY_ICON: Record<string, string> = {
  deals: 'pi-briefcase',
  contacts: 'pi-user',
  companies: 'pi-building',
  tasks: 'pi-check-square',
  docs: 'pi-file',
  finance: 'pi-wallet',
  logs: 'pi-history',
  automations: 'pi-bolt',
  directories: 'pi-folder-open',
}

/** Prefer localized name; fall back to the API-provided label for unknown keys. */
function categoryName(key: string): string {
  const label = props.reset.categories.value.find((c) => c.key === key)?.label ?? key
  return t(`settings.system.reset.categories.${key}.name`, label)
}

function categoryDesc(key: string): string {
  return t(`settings.system.reset.categories.${key}.desc`, '')
}
</script>

<style lang="scss" scoped>
.reset-cats {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  overflow: hidden;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.reset-cats__master {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  background: var(--mg-surface-hover);
  cursor: pointer;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.reset-cats__master-label {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.reset-cats__master-count {
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;
  flex-shrink: 0;

  // Inverted dark scale: muted text needs surface-600 to read on dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.reset-cat-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  cursor: pointer;
  transition: background var(--app-transition-fast);

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: var(--mg-surface-hover);
  }

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }

  &--selected {
    background: var(--p-primary-50);

    &:hover {
      background: var(--p-primary-50);
    }

    // Dark: navy-950 tint (top-level :deep-free scoped override — reactive tokens
    // keep both themes correct without a dead `.app-dark &` inside a nested block).
    .app-dark & {
      background: var(--p-primary-950);

      &:hover {
        background: var(--p-primary-950);
      }
    }
  }
}

.reset-cat-row__icon-wrap {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: $radius-md;
  background: var(--p-surface-50);
  display: flex;
  align-items: center;
  justify-content: center;
}

.reset-cat-row__icon {
  font-size: $font-size-md;
  color: $surface-600;

  // Inverted dark scale: icon needs surface-600 to read on the dark plaque.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.reset-cat-row__body {
  flex: 1;
  min-width: 0;
}

.reset-cat-row__name {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.reset-cat-row__desc {
  font-size: $font-size-xs;
  color: $surface-500;
  margin-top: 2px;

  // Inverted dark scale: muted text needs surface-600 to read on dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.reset-cat-row__count {
  flex-shrink: 0;
  white-space: nowrap;
  font-size: $font-size-xs;
}
</style>
