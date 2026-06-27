<template>
  <div class="tasks-qc">
    <div class="tasks-qc__row">
      <!-- 1. Entity picker (company / contact) -->
      <div ref="entityPickerWrap" class="tasks-qc__entity-picker">
        <div class="tasks-qc__entity-field" :class="{ 'tasks-qc__entity-field--selected': selectedEntity }">
          <i :class="entityIcon" class="tasks-qc__entity-prefix-icon" />
          <input
            ref="entityInputRef"
            v-model="entityQuery"
            class="tasks-qc__entity-input"
            :placeholder="t('tasks.quickCreate.entityPlaceholder')"
            autocomplete="off"
            @focus="onEntityFocus"
            @input="onEntityInput"
            @keydown.escape="closeEntityDropdown"
          />
          <button
            v-if="selectedEntity"
            type="button"
            class="tasks-qc__entity-clear"
            @click="clearEntity"
          >
            <i class="pi pi-times" />
          </button>
        </div>

        <!-- Entity dropdown -->
        <div v-if="entityDropdownOpen && (entityResults.length > 0 || (entityQuery.length > 0 && !entityLoading))" class="tasks-qc__entity-dropdown">
          <div v-if="entityLoading" class="tasks-qc__entity-loading">
            <i class="pi pi-spin pi-spinner" />
          </div>
          <template v-else>
            <button
              v-for="item in entityResults"
              :key="`${item.type}:${item.id}`"
              type="button"
              class="tasks-qc__entity-option"
              @click="selectEntity(item)"
            >
              <span class="tasks-qc__entity-avatar" :class="`tasks-qc__entity-avatar--${item.type}`">
                <i :class="item.type === 'company' ? 'pi pi-building' : 'pi pi-user'" />
              </span>
              <span class="tasks-qc__entity-info">
                <span class="tasks-qc__entity-name">{{ item.name }}</span>
                <span class="tasks-qc__entity-sub">
                  {{ item.type === 'company' ? t('tasks.quickCreate.company') : t('tasks.quickCreate.contact') }}
                  <template v-if="item.type === 'contact' && item.companyName"> · {{ item.companyName }}</template>
                </span>
              </span>
            </button>

            <button
              v-if="entityQuery.trim() && entityResults.length === 0"
              type="button"
              class="tasks-qc__entity-option tasks-qc__entity-option--create"
              @click="closeEntityDropdown"
            >
              {{ t('tasks.quickCreate.notFound', { q: entityQuery.trim() }) }}
            </button>
          </template>
        </div>
      </div>

      <!-- 2. Task title -->
      <input
        ref="titleInputRef"
        v-model="form.title"
        class="tasks-qc__title-input"
        :class="{ 'tasks-qc__title-input--error': titleError }"
        :placeholder="t('tasks.quickCreate.titlePlaceholder')"
        @keydown="onTitleKeydown"
      />

      <!-- 3. Kind select -->
      <div class="tasks-qc__select-wrap">
        <Select
          v-model="form.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="tasks-qc__kind-select"
        >
          <template #value="{ value }">
            <span class="tasks-qc__select-value">
              <i :class="kindIconFn(value)" />
              {{ t(`tasks.board.taskTypes.${value}`) }}
            </span>
          </template>
        </Select>
      </div>

      <!-- 4. Due date -->
      <DatePicker
        v-model="form.due_at"
        show-time
        hour-format="24"
        date-format="dd.mm.yy"
        :placeholder="t('tasks.quickCreate.dueLabel')"
        class="tasks-qc__date-picker"
        show-button-bar
      />

      <!-- 5. Responsible -->
      <Select
        v-model="form.responsible_id"
        :options="users"
        option-label="full_name"
        option-value="id"
        :loading="usersLoading"
        :placeholder="t('tasks.quickCreate.responsibleLabel')"
        filter
        class="tasks-qc__responsible-select"
      />

      <!-- Actions -->
      <Button
        :label="t('tasks.quickCreate.createBtn')"
        icon="pi pi-check"
        severity="primary"
        :loading="creating"
        @click="onSubmit"
      />
      <button type="button" class="tasks-qc__cancel-btn" @click="emit('cancel')">
        {{ t('tasks.quickCreate.cancelBtn') }}
      </button>
    </div>
    <p v-if="titleError" class="tasks-qc__error">{{ titleError }}</p>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import { activityApi } from '@/api/activity'
import { companiesApi } from '@/api/crm/companies'
import { contactsApi } from '@/api/crm/contacts'
import { kindIcon as kindIconFn } from '@/utils/activity'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import type { ActivityKind } from '@/entities/activity'

interface EntityResult {
  id: number
  type: 'company' | 'contact'
  name: string
  companyName?: string
}

const emit = defineEmits<{
  created: [activity: import('@/entities/activity').ActivityDto]
  cancel: []
}>()

const { t } = useI18n()
const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

// ── Entity picker ──────────────────────────────────────────────────────────────

const entityPickerWrap = ref<HTMLElement | null>(null)
const entityInputRef = ref<HTMLInputElement | null>(null)
const entityQuery = ref('')
const entityDropdownOpen = ref(false)
const entityLoading = ref(false)
const entityResults = ref<EntityResult[]>([])
const selectedEntity = ref<EntityResult | null>(null)

const entityIcon = computed(() => {
  if (!selectedEntity.value) return 'pi pi-search'
  return selectedEntity.value.type === 'company' ? 'pi pi-building' : 'pi pi-user'
})

let entityDebounce: ReturnType<typeof setTimeout> | null = null

function onEntityFocus() {
  if (entityQuery.value.length > 0) {
    entityDropdownOpen.value = true
  }
}

function onEntityInput() {
  selectedEntity.value = null
  if (entityDebounce) clearTimeout(entityDebounce)
  if (!entityQuery.value.trim()) {
    entityDropdownOpen.value = false
    entityResults.value = []
    return
  }
  entityDropdownOpen.value = true
  entityLoading.value = true
  entityDebounce = setTimeout(() => void fetchEntities(), 250)
}

async function fetchEntities() {
  const q = entityQuery.value.trim()
  if (!q) return
  try {
    const [companiesRes, contactsRes] = await Promise.all([
      companiesApi.list({ search: q, per_page: 6 }),
      contactsApi.list({ search: q, per_page: 6 }),
    ])
    const results: EntityResult[] = [
      ...companiesRes.data.slice(0, 3).map((c) => ({
        id: c.id,
        type: 'company' as const,
        name: c.name,
      })),
      ...contactsRes.data.slice(0, 3).map((ct) => ({
        id: ct.id,
        type: 'contact' as const,
        name: ct.full_name,
        companyName: ct.company_links?.[0]?.company?.name,
      })),
    ]
    entityResults.value = results.slice(0, 6)
  } catch {
    entityResults.value = []
  } finally {
    entityLoading.value = false
  }
}

function selectEntity(item: EntityResult) {
  selectedEntity.value = item
  entityQuery.value = item.name
  entityDropdownOpen.value = false
}

function clearEntity() {
  selectedEntity.value = null
  entityQuery.value = ''
  entityResults.value = []
  entityDropdownOpen.value = false
  entityInputRef.value?.focus()
}

function closeEntityDropdown() {
  entityDropdownOpen.value = false
}

function onOutsideClick(e: MouseEvent) {
  if (entityPickerWrap.value && !entityPickerWrap.value.contains(e.target as Node)) {
    closeEntityDropdown()
  }
}

onMounted(() => {
  document.addEventListener('click', onOutsideClick)
  void loadUsers()
})
onUnmounted(() => {
  document.removeEventListener('click', onOutsideClick)
})

// ── Form ───────────────────────────────────────────────────────────────────────

interface QcForm {
  title: string
  kind: ActivityKind
  due_at: Date | null
  responsible_id: number | null
}

const form = ref<QcForm>({
  title: '',
  kind: 'task',
  due_at: null,
  responsible_id: null,
})

const titleError = ref<string | null>(null)
const creating = ref(false)

const kindOptions = computed<Array<{ label: string; value: ActivityKind }>>(() => [
  { label: t('tasks.board.taskTypes.call'), value: 'call' },
  { label: t('tasks.board.taskTypes.meeting'), value: 'meeting' },
  { label: t('tasks.board.taskTypes.task'), value: 'task' },
  { label: t('tasks.board.taskTypes.note'), value: 'note' },
  { label: t('tasks.board.taskTypes.follow_up'), value: 'follow_up' },
])

function onTitleKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void onSubmit()
  } else if (e.key === 'Escape') {
    emit('cancel')
  }
}

async function onSubmit() {
  if (creating.value) return

  titleError.value = null
  if (!form.value.title.trim()) {
    titleError.value = t('errors.validation')
    return
  }
  creating.value = true
  try {
    const targetType = selectedEntity.value?.type === 'company'
      ? 'company' as const
      : selectedEntity.value?.type === 'contact'
        ? 'contact' as const
        : null
    const activity = await activityApi.createActivity({
      kind: form.value.kind,
      title: form.value.title.trim(),
      due_at: form.value.due_at ? form.value.due_at.toISOString() : null,
      responsible_id: form.value.responsible_id,
      target_type: targetType,
      target_id: selectedEntity.value?.id ?? null,
    })
    emit('created', activity)
    // Reset form
    form.value = { title: '', kind: 'task', due_at: null, responsible_id: null }
    selectedEntity.value = null
    entityQuery.value = ''
  } catch {
    titleError.value = t('errors.server_error')
  } finally {
    creating.value = false
  }
}

// Clear title error when user types
watch(() => form.value.title, () => {
  if (titleError.value) titleError.value = null
})
</script>

<style lang="scss" scoped>
.tasks-qc {
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  padding: 14px $space-5;

  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

.tasks-qc__row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.tasks-qc__error {
  margin: $space-1 0 0;
  font-size: $font-size-xs;
  color: $color-danger;
}

// ── Entity picker ──────────────────────────────────────────────────────────────

.tasks-qc__entity-picker {
  position: relative;
  min-width: 250px;
  flex-shrink: 0;
}

.tasks-qc__entity-field {
  display: flex;
  align-items: center;
  height: 38px;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  padding: 0 $space-2;
  gap: $space-1;
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

  &--selected {
    border-color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
    }
  }
}

.tasks-qc__entity-prefix-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;

  .tasks-qc__entity-field--selected & {
    color: $primary-900;

    .app-dark & {
      color: $primary-300;
    }
  }
}

.tasks-qc__entity-input {
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

.tasks-qc__entity-clear {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border: none;
  background: transparent;
  color: $surface-400;
  cursor: pointer;
  border-radius: $radius-pill;
  padding: 0;
  flex-shrink: 0;

  &:hover {
    color: $surface-700;
    background: $surface-100;

    .app-dark & {
      color: var(--p-surface-200);
      background: var(--p-surface-600);
    }
  }

  .pi {
    font-size: $font-size-2xs;
  }
}

// ── Entity dropdown ───────────────────────────────────────────────────────────

.tasks-qc__entity-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  padding: $space-1;
  z-index: 100;

  .app-dark & {
    background: var(--p-surface-700);
    border-color: var(--p-surface-600);
  }
}

.tasks-qc__entity-loading {
  padding: $space-2;
  text-align: center;
  color: $surface-400;
  font-size: $font-size-sm;
}

.tasks-qc__entity-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2 $space-2;
  border: none;
  background: transparent;
  border-radius: $radius-sm;
  text-align: left;
  cursor: pointer;
  transition: background-color var(--app-transition-fast);

  &:hover {
    background: $surface-50;

    .app-dark & {
      background: var(--p-surface-600);
    }
  }

  &--create {
    font-size: $font-size-xs;
    color: $primary-900;
    justify-content: center;
    font-weight: $font-weight-medium;

    .app-dark & {
      color: $primary-300;
    }
  }
}

.tasks-qc__entity-avatar {
  width: 26px;
  height: 26px;
  border-radius: $radius-sm;
  background: $primary-100;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(23, 39, 71, 0.3);
  }

  &--contact {
    border-radius: $radius-pill;
  }

  .pi {
    font-size: $font-size-xs;
    color: $primary-900;

    .app-dark & {
      color: $primary-300;
    }
  }
}

.tasks-qc__entity-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.tasks-qc__entity-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.tasks-qc__entity-sub {
  font-size: $font-size-2xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ── Title input ───────────────────────────────────────────────────────────────

.tasks-qc__title-input {
  flex: 1;
  min-width: 200px;
  height: 38px;
  padding: 0 $space-3;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  font-size: $font-size-sm;
  color: $surface-800;
  outline: none;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-700);
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }

  &:focus {
    border-color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
    }
  }

  &--error {
    border-color: $color-danger;
  }
}

// ── Kind select ───────────────────────────────────────────────────────────────

.tasks-qc__select-wrap {
  flex-shrink: 0;
}

.tasks-qc__kind-select {
  height: 38px;
  min-width: 130px;
}

.tasks-qc__select-value {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
}

// ── Date picker ───────────────────────────────────────────────────────────────

.tasks-qc__date-picker {
  width: 170px;
  flex-shrink: 0;
}

// ── Responsible select ────────────────────────────────────────────────────────

.tasks-qc__responsible-select {
  width: 160px;
  flex-shrink: 0;
}

// ── Cancel button ─────────────────────────────────────────────────────────────

.tasks-qc__cancel-btn {
  display: inline-flex;
  align-items: center;
  height: 38px;
  padding: 0 $space-3;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-600;
  cursor: pointer;
  border-radius: $radius-md;
  transition: color var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-800;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }
}
</style>
