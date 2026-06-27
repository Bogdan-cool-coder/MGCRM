<template>
  <div class="tasks-bulk-bar">
    <!-- Cancel -->
    <button type="button" class="tasks-bulk-bar__cancel" @click="emit('cancel')">
      <i class="pi pi-times" />
      {{ t('tasks.bulk.cancel') }}
    </button>

    <!-- Select-all tri-state checkbox -->
    <label class="tasks-bulk-bar__select-all">
      <span
        class="tasks-bulk-bar__checkbox"
        :class="{
          'tasks-bulk-bar__checkbox--checked': allSelected,
          'tasks-bulk-bar__checkbox--indeterminate': someSelected,
        }"
        @click="onSelectAllClick"
      >
        <i v-if="allSelected" class="pi pi-check" />
        <span v-else-if="someSelected" class="tasks-bulk-bar__minus" />
      </span>
    </label>

    <!-- Count -->
    <span class="tasks-bulk-bar__count">
      {{ t('tasks.bulk.selected', { n: selectedCount }) }}
    </span>

    <div class="tasks-bulk-bar__divider" />

    <!-- Actions -->
    <button
      type="button"
      class="tasks-bulk-bar__action"
      :disabled="selectedCount === 0"
      @click="emit('pin')"
    >
      <i class="pi pi-bookmark-fill" />
      {{ t('tasks.bulk.pin') }}
    </button>
    <button
      type="button"
      class="tasks-bulk-bar__action"
      :disabled="selectedCount === 0"
      @click="emit('reopen')"
    >
      <i class="pi pi-refresh" />
      {{ t('tasks.bulk.reopen') }}
    </button>
    <button
      type="button"
      class="tasks-bulk-bar__action tasks-bulk-bar__action--danger"
      :disabled="selectedCount === 0"
      @click="emit('delete')"
    >
      <i class="pi pi-trash" />
      {{ t('tasks.bulk.delete') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  selectedCount: number
  totalVisible: number
}>()

const emit = defineEmits<{
  cancel: []
  pin: []
  reopen: []
  delete: []
  selectAll: []
  clearSelection: []
}>()

const { t } = useI18n()

const allSelected = computed(
  () => props.totalVisible > 0 && props.selectedCount === props.totalVisible,
)
const someSelected = computed(
  () => props.selectedCount > 0 && props.selectedCount < props.totalVisible,
)

function onSelectAllClick() {
  if (allSelected.value) {
    emit('clearSelection')
  } else {
    emit('selectAll')
  }
}
</script>

<style lang="scss" scoped>
.tasks-bulk-bar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: 9px $space-5;
  flex-wrap: wrap;
  background: $primary-100;
  border-bottom: 1px solid $primary-900;
  flex-shrink: 0;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(23, 39, 71, 0.25); // dark mode primary-100 alpha — no dedicated dark token
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border-bottom-color: rgba(23, 39, 71, 0.6);
  }
}

.tasks-bulk-bar__cancel {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 4px $space-2;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-600;
  cursor: pointer;
  border-radius: $radius-sm;
  transition: background-color var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-300);
  }

  &:hover {
    background: $surface-100;

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  .pi {
    font-size: $font-size-xs;
  }
}

.tasks-bulk-bar__select-all {
  display: flex;
  align-items: center;
  cursor: pointer;
}

.tasks-bulk-bar__checkbox {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 17px;
  height: 17px;
  border-radius: $radius-xs;
  border: 1px solid $surface-400;
  background: $surface-card;
  cursor: pointer;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-500);
    background: var(--p-surface-700);
  }

  &--checked {
    background: $primary-900;
    border-color: $primary-900;
    color: $surface-0;

    .pi {
      font-size: $font-size-3xs;
    }
  }

  &--indeterminate {
    border-color: $primary-900;
  }
}

.tasks-bulk-bar__minus {
  width: 9px;
  height: 2px;
  background: $primary-900;
  border-radius: $radius-2xs; // 2px — snap from 1px
}

.tasks-bulk-bar__count {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-900;
  white-space: nowrap;
}

.tasks-bulk-bar__divider {
  width: 1px;
  height: 24px;
  background: $surface-200;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-600);
  }
}

.tasks-bulk-bar__action {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 4px $space-2;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  background: $surface-card;
  font-size: $font-size-sm;
  color: $surface-700;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  height: 31px;

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-700);
    color: var(--p-surface-200);
  }

  &:hover:not(:disabled) {
    border-color: $primary-900;
    color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
      color: $primary-300;
    }
  }

  &:disabled {
    opacity: 0.45;
    cursor: default;
  }

  &--danger {
    color: $color-danger-text;
    border-color: $color-danger-border;

    .app-dark & {
      color: var(--p-red-300);
      border-color: var(--p-red-800);
    }

    &:hover:not(:disabled) {
      background: $color-danger-bg;
      border-color: $color-danger;
      color: $color-danger;

      .app-dark & {
        background: var(--p-red-900);
        border-color: var(--p-red-500);
        color: var(--p-red-300);
      }
    }
  }

  .pi {
    font-size: $font-size-sm;
  }
}
</style>
