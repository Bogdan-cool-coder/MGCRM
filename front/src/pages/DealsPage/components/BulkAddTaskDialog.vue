<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deals.page.bulk.addTaskDialog.title', { n: dealIds.length })"
    modal
    style="width: 480px"
    :closable="!saving"
    class="bulk-task-dialog"
  >
    <div class="bulk-task-dialog__body">
      <!-- Kind -->
      <div class="bulk-task-dialog__field">
        <label class="bulk-task-dialog__label">
          {{ t('activity.form.kind') }} <span class="req">*</span>
        </label>
        <div class="bulk-task-dialog__kind-row">
          <button
            v-for="opt in kindOptions"
            :key="opt.value"
            class="bulk-task-dialog__kind-btn"
            :class="{ 'bulk-task-dialog__kind-btn--active': form.kind === opt.value }"
            type="button"
            @click="form.kind = opt.value"
          >
            <i :class="opt.icon" />
            <span>{{ opt.label }}</span>
          </button>
        </div>
      </div>

      <!-- Title -->
      <div class="bulk-task-dialog__field">
        <label class="bulk-task-dialog__label">
          {{ t('activity.form.title') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.title"
          class="w-full"
          :class="{ 'p-invalid': errors.title }"
          :placeholder="t('activity.form.titlePlaceholder')"
        />
        <small v-if="errors.title" class="p-error">{{ errors.title }}</small>
      </div>

      <!-- Responsible -->
      <div class="bulk-task-dialog__field">
        <label class="bulk-task-dialog__label">{{ t('activity.form.responsible') }}</label>
        <Select
          v-model="form.responsible_id"
          :options="users"
          option-label="full_name"
          option-value="id"
          filter
          show-clear
          class="w-full"
          :placeholder="t('activity.form.responsible')"
          :loading="loadingUsers"
        />
      </div>

      <!-- Due at -->
      <div class="bulk-task-dialog__field">
        <label class="bulk-task-dialog__label">{{ t('activity.form.dueAt') }}</label>
        <DatePicker
          v-model="form.due_at"
          show-icon
          show-time
          hour-format="24"
          date-format="dd.mm.yy"
          class="w-full"
        />
      </div>
    </div>

    <template #footer>
      <div class="bulk-task-dialog__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.page.bulk.addTaskDialog.apply')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import { useMutation } from '@/composables/async/useMutation'
import { activityApi } from '@/api/activity'
import { usersApi, type UserOptionDto } from '@/api/users'
import type { ActivityKind } from '@/entities/activity'

interface BulkTaskForm {
  kind: ActivityKind
  title: string
  responsible_id: number | null
  due_at: Date | null
}

const defaultForm = (): BulkTaskForm => ({
  kind: 'task',
  title: '',
  responsible_id: null,
  due_at: null,
})

const props = defineProps<{
  modelValue: boolean
  dealIds: number[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  done: []
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = ref<BulkTaskForm>(defaultForm())
const errors = ref<Record<string, string>>({})
const users = ref<UserOptionDto[]>([])
const loadingUsers = ref(false)

const mutation = useMutation()
const saving = computed(() => mutation.isPending.value)

const kindOptions = computed(() => [
  { value: 'task' as ActivityKind, label: t('sales.deals.page.taskTypes.task'), icon: 'pi pi-check-square' },
  { value: 'call' as ActivityKind, label: t('sales.deals.page.taskTypes.call'), icon: 'pi pi-phone' },
  { value: 'meeting' as ActivityKind, label: t('sales.deals.page.taskTypes.meeting'), icon: 'pi pi-users' },
  { value: 'follow_up' as ActivityKind, label: t('sales.deals.page.taskTypes.follow_up'), icon: 'pi pi-reply' },
])

async function loadUsers() {
  if (users.value.length > 0) return
  loadingUsers.value = true
  try {
    users.value = await usersApi.getUsers()
  } finally {
    loadingUsers.value = false
  }
}

onMounted(() => {
  void loadUsers()
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = defaultForm()
      errors.value = {}
    }
  },
)

function formatDate(d: Date | null): string | null {
  if (!d) return null
  return d.toISOString()
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.title || form.value.title.trim().length < 2) {
    errs.title = t('activity.form.titleRequired')
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function onSubmit() {
  if (!validate()) return

  await mutation.run(() =>
    activityApi.bulkCreateActivities({
      deal_ids: props.dealIds,
      type: form.value.kind,
      title: form.value.title,
      responsible_id: form.value.responsible_id,
      due_at: formatDate(form.value.due_at),
    }),
  )

  visible.value = false
  emit('done')
}
</script>

<style lang="scss" scoped>
.bulk-task-dialog {
  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-2 0;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__kind-row {
    display: flex;
    gap: $space-2;
    flex-wrap: wrap;
  }

  &__kind-btn {
    display: inline-flex;
    align-items: center;
    gap: $space-1;
    padding: $space-1 $space-3;
    border: 1px solid $surface-200;
    border-radius: $radius-md;
    background: transparent;
    font-size: $font-size-sm;
    color: $surface-600;
    cursor: pointer;
    transition: all 0.15s;

    &:hover {
      border-color: $primary-color;
      color: $primary-color;
    }

    &--active {
      background: $primary-color;
      border-color: $primary-color;
      color: $surface-0;

      &:hover {
        color: $surface-0;
      }
    }

    .app-dark & {
      border-color: var(--p-surface-200);
      color: var(--p-surface-300);
    }
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
