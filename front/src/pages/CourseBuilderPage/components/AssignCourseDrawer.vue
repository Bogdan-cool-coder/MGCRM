<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    :style="{ width: '560px' }"
  >
    <template #header>
      <div class="d-flex align-items-center gap-3 w-100">
        <span class="flex-grow-1 fw-semibold">{{ t('onboarding.assignments.drawer.title') }}</span>
        <Button
          :label="t('onboarding.assignments.drawer.cancel')"
          severity="secondary"
          outlined
          size="small"
          @click="visible = false"
        />
        <Button
          :label="t('onboarding.assignments.drawer.submit')"
          icon="pi pi-send"
          size="small"
          :loading="saving"
          :disabled="selectedUsers.length === 0"
          @click="submit"
        />
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          size="small"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>

    <div class="assign-drawer-body">
      <!-- Course info -->
      <div class="mb-4 d-flex align-items-center gap-2">
        <span class="text-muted">{{ t('onboarding.assignments.columns.course') }}:</span>
        <Tag severity="info" :value="courseName" />
      </div>

      <!-- Users MultiSelect -->
      <div class="mb-3">
        <label class="form-label required">{{ t('onboarding.assignments.drawer.employees') }}</label>
        <MultiSelect
          v-model="selectedUsers"
          :options="userOptions"
          option-label="label"
          option-value="id"
          :placeholder="t('onboarding.assignments.drawer.employeesPlaceholder')"
          filter
          class="w-100"
          :loading="loadingUsers"
        />
        <div v-if="selectedUsers.length > 0" class="mt-2">
          <Badge :value="selectedUsers.length" severity="info" />
          <span class="ms-2 text-muted" style="font-size: 0.85rem;">
            {{ t('onboarding.assignments.drawer.employees') }}
          </span>
        </div>
      </div>

      <!-- Deadline -->
      <div class="mb-3">
        <label class="form-label">{{ t('onboarding.assignments.drawer.deadline') }}</label>
        <DatePicker
          v-model="deadline"
          date-format="dd.mm.yy"
          show-icon
          :min-date="today"
          class="w-100"
        />
        <small class="text-muted">{{ t('onboarding.assignments.drawer.deadlineHint', { n: deadlineDays ?? '—' }) }}</small>
      </div>
    </div>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import Badge from 'primevue/badge'
import { useToast } from 'primevue/usetoast'
import { apiClient } from '@/api/client'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { BulkAssignResult } from '@/entities/assignment'

const props = defineProps<{
  courseId: number
  courseName: string
  deadlineDays: number | null
}>()

const emit = defineEmits<{
  assigned: [result: BulkAssignResult]
}>()

const { t } = useI18n()
const toast = useToast()
const visible = defineModel<boolean>('visible', { default: false })

const saving = ref(false)
const loadingUsers = ref(false)
const selectedUsers = ref<number[]>([])
const deadline = ref<Date | null>(null)

const today = new Date()

interface UserOption {
  id: number
  label: string
}

const userOptions = ref<UserOption[]>([])

async function loadUsers(): Promise<void> {
  loadingUsers.value = true
  try {
    const res = await apiClient.get<{ data: { id: number; first_name: string; last_name: string; email: string }[] }>('/api/users')
    userOptions.value = res.data.data.map((u) => ({
      id: u.id,
      label: `${u.first_name} ${u.last_name} (${u.email})`,
    }))
  } catch {
    // ignore
  } finally {
    loadingUsers.value = false
  }
}

onMounted(() => {
  void loadUsers()
})

async function submit(): Promise<void> {
  if (selectedUsers.value.length === 0) return
  saving.value = true
  try {
    const result = await onboardingAdminApi.createAssignments({
      user_ids: selectedUsers.value,
      course_id: props.courseId,
      due_date: deadline.value ? deadline.value.toISOString().slice(0, 10) : null,
    })
    toast.add({
      severity: 'success',
      summary: t('onboarding.assignments.drawer.successSummary', { assigned: result.assigned }),
      life: 4000,
    })
    if (result.skipped > 0) {
      toast.add({
        severity: 'warn',
        summary: t('onboarding.assignments.drawer.skippedWarning', { skipped: result.skipped }),
        life: 5000,
      })
    }
    emit('assigned', result)
    visible.value = false
    selectedUsers.value = []
    deadline.value = null
  } catch {
    toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
.assign-drawer-body {
  padding: $space-2;
}

.form-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  margin-bottom: $space-1;
  display: block;

  &.required::after {
    content: ' *';
    color: var(--p-red-500);
  }
}

/* close button rendered in custom #header slot */
</style>
