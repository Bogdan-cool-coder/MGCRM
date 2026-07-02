<template>
  <div class="team-filter-bar">
    <!-- Search -->
    <div class="team-filter-bar__search-wrap">
      <i class="pi pi-search team-filter-bar__search-icon" />
      <input
        :value="q"
        class="team-filter-bar__search-input"
        :placeholder="t('tasks.team.filterBar.searchPlaceholder')"
        @input="onQInput"
      />
      <button
        v-if="q"
        type="button"
        class="team-filter-bar__search-clear"
        :title="t('tasks.team.filterBar.clear')"
        @click="emit('update:q', '')"
      >
        <i class="pi pi-times" />
      </button>
    </div>

    <!-- Responsible select -->
    <Select
      :model-value="responsibleId"
      :options="users"
      option-label="full_name"
      option-value="id"
      :placeholder="t('tasks.team.filterBar.responsible')"
      show-clear
      filter
      :loading="usersLoading"
      class="team-filter-bar__responsible"
      @update:model-value="emit('update:responsibleId', $event ?? null)"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import { useUsersCache } from '@/composables/crm/useUsersCache'

defineProps<{
  q: string
  responsibleId: number | null
}>()

const emit = defineEmits<{
  'update:q': [v: string]
  'update:responsibleId': [v: number | null]
}>()

const { t } = useI18n()
const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

// Debounced q input
let debounceTimer: ReturnType<typeof setTimeout> | null = null

function onQInput(e: Event) {
  const val = (e.target as HTMLInputElement).value
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    emit('update:q', val)
  }, 350)
}

onMounted(() => {
  void loadUsers()
})
</script>

<style lang="scss" scoped>
.team-filter-bar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

// ── Search input ──────────────────────────────────────────────────────────────

.team-filter-bar__search-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 0 1 320px;
  height: 36px;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  padding: 0 $space-3;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-700);
  }

  &:focus-within {
    border-color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
    }
  }
}

.team-filter-bar__search-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.team-filter-bar__search-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;
  outline: none;
  min-width: 0;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

.team-filter-bar__search-clear {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border: none;
  background: transparent;
  color: $surface-400;
  cursor: pointer;
  border-radius: $radius-sm;
  flex-shrink: 0;
  transition: color var(--app-transition-fast);

  &:hover {
    color: $surface-700;
  }

  .app-dark & {
    color: var(--p-surface-500);

    &:hover {
      color: var(--p-surface-200);
    }
  }
}

// ── Responsible select ────────────────────────────────────────────────────────

.team-filter-bar__responsible {
  height: 36px;
  width: 220px;
  flex-shrink: 0;
}
</style>
