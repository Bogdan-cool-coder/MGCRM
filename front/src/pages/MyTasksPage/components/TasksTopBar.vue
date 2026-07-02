<template>
  <div class="tasks-top-bar">
    <!-- Section icon tile -->
    <div class="tasks-top-bar__icon-tile">
      <i class="pi pi-check-square tasks-top-bar__section-icon" />
    </div>

    <!-- Title block -->
    <div class="tasks-top-bar__title-block">
      <h1 class="tasks-top-bar__title">{{ t('tasks.board.title') }}</h1>
      <p class="tasks-top-bar__subtitle">
        MACRO Global ·
        <span>{{ totalCount }} {{ t('activity.myTasksPage.title').toLowerCase() }}</span>
        <template v-if="overdueCount > 0">
          · <span class="tasks-top-bar__overdue-count">{{ t('tasks.topbar.overdueLabel', { n: overdueCount }) }}</span>
        </template>
      </p>
    </div>

    <!-- My / Team toggle (admin/director/manager only) -->
    <div v-if="showModeToggle" class="tasks-top-bar__mode-segment">
      <button
        type="button"
        class="tasks-top-bar__mode-btn"
        :class="{ 'tasks-top-bar__mode-btn--active': (mode ?? 'my') === 'my' }"
        @click="emit('update:mode', 'my')"
      >
        {{ t('tasks.team.toggle.my') }}
      </button>
      <button
        type="button"
        class="tasks-top-bar__mode-btn"
        :class="{ 'tasks-top-bar__mode-btn--active': (mode ?? 'my') === 'team' }"
        @click="emit('update:mode', 'team')"
      >
        {{ t('tasks.team.toggle.team') }}
      </button>
    </div>

    <div class="tasks-top-bar__spacer" />

    <!-- Filter button — list view only (kanban has no server-side filter support) -->
    <button
      v-if="view === 'list'"
      type="button"
      class="tasks-top-bar__filter-btn"
      :class="{ 'tasks-top-bar__filter-btn--active': filterActive }"
      @click="emit('toggleFilter')"
    >
      <i class="pi pi-search tasks-top-bar__filter-icon" />
      {{ t('tasks.topbar.filterBtn') }}
      <span v-if="filterCount > 0" class="tasks-top-bar__filter-badge">{{ filterCount }}</span>
    </button>

    <!-- Scope segment (kanban only) -->
    <div v-if="view === 'kanban'" class="tasks-top-bar__scope-segment">
      <button
        v-for="s in SCOPES"
        :key="s.value"
        type="button"
        class="tasks-top-bar__scope-btn"
        :class="{ 'tasks-top-bar__scope-btn--active': scope === s.value }"
        @click="emit('update:scope', s.value)"
      >
        {{ s.label }}
      </button>
    </div>

    <!-- View switch -->
    <div class="tasks-top-bar__view-segment">
      <button
        type="button"
        class="tasks-top-bar__view-btn"
        :class="{ 'tasks-top-bar__view-btn--active': view === 'kanban' }"
        :title="t('tasks.page.viewKanban')"
        @click="emit('update:view', 'kanban')"
      >
        <i class="pi pi-th-large" />
      </button>
      <button
        type="button"
        class="tasks-top-bar__view-btn"
        :class="{ 'tasks-top-bar__view-btn--active': view === 'list' }"
        :title="t('tasks.page.viewList')"
        @click="emit('update:view', 'list')"
      >
        <i class="pi pi-list" />
      </button>
    </div>

    <!-- More menu (⋮) -->
    <button
      type="button"
      class="tasks-top-bar__more-btn"
      @click="onMoreClick"
    >
      <i class="pi pi-ellipsis-v" />
    </button>
    <Menu ref="moreMenuRef" :model="moreMenuItems" popup />

    <!-- Create button -->
    <button
      type="button"
      class="tasks-top-bar__create-btn"
      @click="emit('toggleQuickCreate')"
    >
      <i class="pi pi-plus" />
      {{ t('tasks.topbar.createBtn') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Menu from 'primevue/menu'
import type { TaskScope } from '../composables/useTaskBoard'

type TaskView = 'kanban' | 'list'
type TasksMode = 'my' | 'team'

defineProps<{
  view: TaskView
  scope: TaskScope
  filterActive: boolean
  filterCount: number
  totalCount: number
  overdueCount: number
  /** Current mode: 'my' (default) or 'team'. */
  mode?: TasksMode
  /** If true, show the My/Team toggle (admin/director/manager only). */
  showModeToggle?: boolean
}>()

const emit = defineEmits<{
  'update:view': [v: TaskView]
  'update:scope': [v: TaskScope]
  'update:mode': [v: TasksMode]
  toggleFilter: []
  toggleQuickCreate: []
  enterSelectMode: []
}>()

const { t } = useI18n()
const toast = useToast()
const moreMenuRef = ref<InstanceType<typeof Menu> | null>(null)

const SCOPES = computed(() => [
  { label: t('tasks.board.scope.day'), value: 'day' as TaskScope },
  { label: t('tasks.board.scope.week'), value: 'week' as TaskScope },
  { label: t('tasks.board.scope.month'), value: 'month' as TaskScope },
])

const moreMenuItems = computed(() => [
  {
    label: t('tasks.topbar.moreMenu.select'),
    icon: 'pi pi-check-square',
    command: () => emit('enterSelectMode'),
  },
  { separator: true },
  {
    label: t('tasks.topbar.moreMenu.calendarSync'),
    icon: 'pi pi-calendar',
    command: () => {
      toast.add({
        severity: 'info',
        summary: t('tasks.topbar.moreMenu.comingSoon'),
        life: 2000,
      })
    },
  },
  {
    label: t('tasks.topbar.moreMenu.exportCsv'),
    icon: 'pi pi-upload',
    command: () => {
      toast.add({
        severity: 'info',
        summary: t('tasks.topbar.moreMenu.comingSoon'),
        life: 2000,
      })
    },
  },
])

function onMoreClick(e: MouseEvent) {
  moreMenuRef.value?.toggle(e)
}
</script>

<style lang="scss" scoped>
.tasks-top-bar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: 14px $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-wrap: wrap;
  flex-shrink: 0;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.tasks-top-bar__icon-tile {
  width: 38px;
  height: 38px;
  border-radius: $radius-md;
  background: $primary-100;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(23, 39, 71, 0.3); // primary-100 dark equivalent — alpha on dark surface
  }
}

.tasks-top-bar__section-icon {
  font-size: $font-size-icon-sm; // 18px — snap from 17px spec
  color: $primary-900;
}

.tasks-top-bar__title-block {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.tasks-top-bar__title {
  margin: 0;
  font-size: $font-size-xl; // ~20px — snap from 19px spec; closest available token
  font-weight: $font-weight-semibold;
  color: $surface-800;
  line-height: 1.2;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.tasks-top-bar__subtitle {
  margin: 0;
  font-size: $font-size-xs;
  color: $surface-500;
  line-height: 1.4;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.tasks-top-bar__overdue-count {
  color: var(--p-red-700);
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-red-400);
  }
}

.tasks-top-bar__spacer {
  flex: 1;
}

// ── Filter button ─────────────────────────────────────────────────────────────

.tasks-top-bar__filter-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  height: 38px;
  padding: 0 $space-3;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  font-size: $font-size-sm;
  color: $surface-600;
  cursor: pointer;
  position: relative;
  transition: all var(--app-transition-fast);
  flex-shrink: 0;
  white-space: nowrap;

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: $primary-900;
    color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
      color: $primary-300;
    }
  }

  &--active {
    background: $primary-100;
    border-color: $primary-900;
    color: $primary-900;

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(23, 39, 71, 0.2);
      border-color: $primary-300;
      color: $primary-300;
    }
  }
}

.tasks-top-bar__filter-icon {
  font-size: $font-size-sm;
}

.tasks-top-bar__filter-badge {
  position: absolute;
  top: -5px;
  right: -5px;
  min-width: 16px;
  height: 16px;
  padding: 0 3px;
  border-radius: $radius-pill;
  background: $color-warning-badge;
  color: $surface-0;
  font-size: $font-size-3xs; // 10px — snap
  font-weight: $font-weight-bold;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

// ── Scope segment ─────────────────────────────────────────────────────────────

.tasks-top-bar__scope-segment {
  display: inline-flex;
  gap: 2px;
  background: $surface-100;
  border-radius: $radius-sm; // 6px — snap from 7px spec
  padding: 3px;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-800);
  }
}

.tasks-top-bar__scope-btn {
  height: 25px;
  padding: 0 $space-2;
  border: none;
  border-radius: $radius-sm;
  background: transparent;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &--active {
    background: $surface-card;
    color: $primary-900;
    box-shadow: $shadow-sm;

    .app-dark & {
      background: var(--p-surface-600);
      color: var(--p-primary-300);
    }
  }
}

// ── Mode segment (My / Team) ──────────────────────────────────────────────────

.tasks-top-bar__mode-segment {
  display: inline-flex;
  gap: 2px;
  background: $surface-100;
  border-radius: $radius-sm;
  padding: 3px;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-800);
  }
}

.tasks-top-bar__mode-btn {
  height: 25px;
  padding: 0 $space-3;
  border: none;
  border-radius: $radius-sm;
  background: transparent;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &--active {
    background: $surface-card;
    color: $primary-900;
    box-shadow: $shadow-sm;

    .app-dark & {
      background: var(--p-surface-600);
      color: var(--p-primary-300);
    }
  }
}

// ── View segment ──────────────────────────────────────────────────────────────

.tasks-top-bar__view-segment {
  display: inline-flex;
  gap: 2px;
  background: $surface-100;
  border-radius: $radius-sm; // 6px — snap from 7px spec
  padding: 3px;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-800);
  }
}

.tasks-top-bar__view-btn {
  width: 31px;
  height: 31px;
  border: none;
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-500;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all var(--app-transition-fast);
  font-size: $font-size-sm;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    background: $surface-200;
    color: $surface-700;

    .app-dark & {
      background: var(--p-surface-700);
      color: var(--p-surface-200);
    }
  }

  &--active {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(23, 39, 71, 0.3);
      color: $primary-300;
    }
  }
}

// ── More button ───────────────────────────────────────────────────────────────

.tasks-top-bar__more-btn {
  width: 31px;
  height: 31px;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: transparent;
  color: $surface-600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all var(--app-transition-fast);
  font-size: $font-size-sm;
  flex-shrink: 0;

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: $primary-900;
    color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
      color: $primary-300;
    }
  }
}

// ── Create button ─────────────────────────────────────────────────────────────

.tasks-top-bar__create-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  height: 38px;
  padding: 0 $space-3;
  border: none;
  border-radius: $radius-md;
  background: $primary-900;
  color: $surface-0;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  cursor: pointer;
  transition: background-color var(--app-transition-fast);
  flex-shrink: 0;
  white-space: nowrap;

  &:hover {
    background: $primary-800;
  }

  .pi {
    font-size: $font-size-sm;
  }
}
</style>
