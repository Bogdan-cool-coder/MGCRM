<template>
  <div class="task-board">
    <!-- Sub-toolbar -->
    <div class="task-board__subtoolbar">
      <InputText
        v-model="taskBoard.searchQuery.value"
        :placeholder="t('tasks.board.searchPlaceholder')"
        class="task-board__search"
      />
      <Select
        v-model="taskBoard.scope.value"
        :options="scopeOptions"
        option-label="label"
        option-value="value"
        class="task-board__scope"
      />
      <Button
        icon="pi pi-plus"
        :label="t('tasks.board.addTask')"
        severity="secondary"
        outlined
        @click="showQuickCreate = !showQuickCreate"
      />
    </div>

    <!-- Inline quick-create form -->
    <Transition name="tqf-slide">
      <TaskQuickForm
        v-if="showQuickCreate"
        mode="create"
        :closable="true"
        :auto-focus="true"
        class="task-board__quick-create"
        @created="onQuickCreated"
        @cancel="showQuickCreate = false"
      />
    </Transition>

    <!-- Loading -->
    <template v-if="taskBoard.loading.value">
      <div class="task-board__columns">
        <div
          v-for="col in 3"
          :key="col"
          class="task-board__col"
        >
          <div class="task-board__col-header task-board__col-header--neutral">
            <Skeleton width="100px" height="16px" />
          </div>
          <div class="task-board__col-body">
            <Skeleton v-for="s in 3" :key="s" height="64px" class="mb-2" />
          </div>
        </div>
      </div>
    </template>

    <!-- All done empty state -->
    <div v-else-if="taskBoard.allDone.value" class="task-board__empty">
      <i class="pi pi-check-circle task-board__empty-icon task-board__empty-icon--success" />
      <p class="task-board__empty-title">{{ t('tasks.board.empty.title') }}</p>
      <p class="task-board__empty-subtitle">{{ t('tasks.board.empty.subtitle') }}</p>
    </div>

    <!-- Board columns -->
    <div v-else class="task-board__columns">
      <div
        v-for="bucket in taskBoard.bucketsData.value"
        :key="bucket.key"
        class="task-board__col"
        :class="{ 'task-board__col--overdue': bucket.key === 'overdue' && bucket.tasks.length > 0 }"
      >
        <!-- Column header -->
        <div
          class="task-board__col-header"
          :class="{
            'task-board__col-header--danger': bucket.key === 'overdue' && bucket.tasks.length > 0,
            'task-board__col-header--neutral': bucket.key !== 'overdue' || bucket.tasks.length === 0,
          }"
        >
          <span class="task-board__col-name">{{ t(`tasks.board.columns.${bucket.key}`) }}</span>
          <span class="task-board__col-count">{{ bucket.tasks.length }}</span>
        </div>

        <!-- Cards -->
        <div class="task-board__col-body">
          <TaskCard
            v-for="task in bucket.tasks"
            :key="task.id"
            :task="task"
            :is-overdue="bucket.key === 'overdue'"
            class="task-board__card"
            @complete="onComplete"
          />
          <div v-if="bucket.tasks.length === 0" class="task-board__col-empty">
            {{ t('tasks.board.columns.noTasks') }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import TaskCard from './TaskCard.vue'
import TaskQuickForm from '@/components/tasks/TaskQuickForm.vue'
import { useTaskBoard } from '../composables/useTaskBoard'
import type { TaskScope } from '../composables/useTaskBoard'
import type { ActivityDto } from '@/entities/activity'

const emit = defineEmits<{
  taskCompleted: []
  taskCreated: [activity: ActivityDto]
  error: [message: string]
}>()

const { t } = useI18n()

const taskBoard = useTaskBoard()
const showQuickCreate = ref(false)

const scopeOptions = computed(() => [
  { label: t('tasks.board.scope.day'), value: 'day' as TaskScope },
  { label: t('tasks.board.scope.week'), value: 'week' as TaskScope },
  { label: t('tasks.board.scope.month'), value: 'month' as TaskScope },
])

async function onComplete(id: number) {
  try {
    await taskBoard.completeTask(id)
    emit('taskCompleted')
  } catch {
    emit('error', t('tasks.board.card.completed'))
  }
}

async function onQuickCreated(activity: ActivityDto) {
  showQuickCreate.value = false
  emit('taskCreated', activity)
  // Reload board so new task appears in correct bucket
  await taskBoard.load()
}

onMounted(() => { void taskBoard.load() })
</script>

<style lang="scss" scoped>
.task-board {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.task-board__subtoolbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 0 $space-3;
  flex-shrink: 0;
}

.task-board__search {
  flex: 1;
  max-width: 280px;
}

.task-board__scope {
  width: 140px;
}

.task-board__quick-create {
  margin-bottom: $space-3;
  flex-shrink: 0;
  max-width: 420px;
}

// Slide-in transition
.tqf-slide-enter-active,
.tqf-slide-leave-active {
  transition: all 0.18s ease;
  overflow: hidden;
}

.tqf-slide-enter-from,
.tqf-slide-leave-to {
  opacity: 0;
  max-height: 0;
  margin-bottom: 0;
}

.tqf-slide-enter-to,
.tqf-slide-leave-from {
  opacity: 1;
  max-height: 200px;
}

.task-board__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-400;
  flex: 1;
}

.task-board__empty-icon {
  font-size: $font-size-icon-2xl;

  &--success {
    color: var(--p-green-500);
  }
}

.task-board__empty-title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.task-board__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}

.task-board__columns {
  display: flex;
  flex-direction: row;
  gap: $space-3;
  overflow-x: auto;
  overflow-y: hidden;
  flex: 1;
  align-items: flex-start;
  padding-bottom: $space-4;
  scrollbar-width: thin;
  scrollbar-color: $surface-300 transparent;
}

.task-board__col {
  width: 260px;
  min-width: 260px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;

  &--overdue .task-board__col-body {
    border-left: 3px solid $color-danger;

    :global(.app-dark) & {
      border-left-color: var(--p-red-400);
    }
  }
}

.task-board__col-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-2 $space-3;
  border-radius: $radius-md $radius-md 0 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  flex-shrink: 0;

  &--neutral {
    background: $surface-50;
    color: $surface-700;

    :global(.app-dark) & {
      background: var(--p-surface-800);
      color: var(--p-surface-200);
    }
  }

  &--danger {
    background: $color-danger-bg;
    color: $color-danger-text;

    :global(.app-dark) & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(255, 90, 68, 0.15); // danger tint in dark mode — alpha blend of $color-danger, no dedicated token
      color: $color-danger;
    }
  }
}

.task-board__col-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-board__col-count {
  font-size: $font-size-xs;
  padding: 1px 6px;
  border-radius: $radius-sm;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(0, 0, 0, 0.08); // count badge translucent fill — no token for alpha-on-bg overlays
  flex-shrink: 0;

  :global(.app-dark) & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(255, 255, 255, 0.1); // count badge translucent fill dark mode — no token
  }
}

.task-board__col-body {
  border: 1px solid $surface-200;
  border-top: none;
  border-radius: 0 0 $radius-md $radius-md;
  padding: $space-2;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  min-height: 80px;
  flex: 1;
  overflow-y: auto;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.task-board__col-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: $font-size-xs;
  color: $surface-400;
  min-height: 60px;
}
</style>
