<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    :style="{ width: '560px' }"
    :show-close-icon="false"
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
          :disabled="!canSubmit"
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
      <!-- Course picker — global mode (no courseId prop) -->
      <div v-if="isGlobalMode" class="mb-4">
        <label class="form-label required">{{ t('onboarding.assignments.drawer.course') }}</label>
        <Select
          v-model="selectedCourseId"
          :options="courseOptions"
          option-label="label"
          option-value="id"
          :placeholder="t('onboarding.assignments.drawer.coursePlaceholder')"
          filter
          class="w-100"
          :loading="loadingCourses"
          @change="onCourseChange"
        />
      </div>

      <!-- Course info — fixed mode (courseId prop passed) -->
      <div v-else class="mb-4 d-flex align-items-center gap-2">
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
          <span class="ms-2 text-muted assign-drawer-body__count-label">
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
        <small class="text-muted">{{ t('onboarding.assignments.drawer.deadlineHint', { n: effectiveDeadlineDays ?? '—' }) }}</small>
      </div>
    </div>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import Badge from 'primevue/badge'
import { useToast } from 'primevue/usetoast'
import { apiClient } from '@/api/client'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { BulkAssignResult } from '@/entities/assignment'
import type { Course } from '@/entities/course'

const props = defineProps<{
  courseId?: number
  courseName?: string
  deadlineDays?: number | null
}>()

const emit = defineEmits<{
  assigned: [result: BulkAssignResult]
}>()

const { t } = useI18n()
const toast = useToast()
const visible = defineModel<boolean>('visible', { default: false })

/** True when the drawer is opened without a pre-selected course (global assignments page). */
const isGlobalMode = computed(() => props.courseId === undefined)

// ─── State ────────────────────────────────────────────────────────────────────

const saving = ref(false)
const loadingUsers = ref(false)
const loadingCourses = ref(false)

const selectedUsers = ref<number[]>([])
const deadline = ref<Date | null>(null)

/** Selected course in global mode */
const selectedCourseId = ref<number | null>(null)
/** Full course object for deadline_days hint in global mode */
const selectedCourse = ref<Course | null>(null)

const today = new Date()

// ─── Options ──────────────────────────────────────────────────────────────────

interface UserOption {
  id: number
  label: string
}

interface CourseOption {
  id: number
  label: string
  course: Course
}

const userOptions = ref<UserOption[]>([])
const courseOptions = ref<CourseOption[]>([])

// ─── Effective values (resolve fixed vs global mode) ─────────────────────────

const effectiveCourseId = computed<number | null>(() => {
  if (!isGlobalMode.value) return props.courseId ?? null
  return selectedCourseId.value
})

const effectiveDeadlineDays = computed<number | null>(() => {
  if (!isGlobalMode.value) return props.deadlineDays ?? null
  return selectedCourse.value?.deadline_days ?? null
})

const canSubmit = computed(
  () => effectiveCourseId.value !== null && selectedUsers.value.length > 0,
)

// ─── Data loading ─────────────────────────────────────────────────────────────

async function loadUsers(): Promise<void> {
  loadingUsers.value = true
  try {
    const res = await apiClient.get<{ data: { id: number; full_name: string; email: string }[] }>('/api/users')
    userOptions.value = res.data.data.map((u) => ({
      id: u.id,
      label: `${u.full_name} (${u.email})`,
    }))
  } catch {
    // ignore — user can retry by reopening
  } finally {
    loadingUsers.value = false
  }
}

async function loadCourses(): Promise<void> {
  if (courseOptions.value.length > 0) return // already loaded
  loadingCourses.value = true
  try {
    const res = await onboardingAdminApi.getCourses({ status: 'published', per_page: 200 })
    courseOptions.value = res.data.map((c) => ({
      id: c.id,
      label: c.title,
      course: c,
    }))
  } catch {
    // ignore
  } finally {
    loadingCourses.value = false
  }
}

function onCourseChange(): void {
  const opt = courseOptions.value.find((c) => c.id === selectedCourseId.value) ?? null
  selectedCourse.value = opt?.course ?? null
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────

watch(visible, (open) => {
  if (open) {
    // Reset form state on every open
    selectedUsers.value = []
    deadline.value = null
    selectedCourseId.value = null
    selectedCourse.value = null

    void loadUsers()
    if (isGlobalMode.value) {
      void loadCourses()
    }
  }
})

// ─── Submit ───────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  try {
    const result = await onboardingAdminApi.createAssignments({
      user_ids: selectedUsers.value,
      course_id: effectiveCourseId.value as number,
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

  .app-dark & {
    color: $surface-300;
  }
}

.assign-drawer-body__count-label {
  font-size: $font-size-sm; // snap from 0.85rem (13.6px→14px)
}

/* close button rendered in custom #header slot */
</style>
