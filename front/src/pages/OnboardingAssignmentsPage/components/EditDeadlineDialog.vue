<template>
  <Dialog
    v-model:visible="visible"
    :header="t('onboarding.assignments.editDeadline')"
    modal
    :style="{ width: '24rem' }"
    :draggable="false"
  >
    <div class="pt-2 mb-3">
      <label class="form-label required">{{ t('onboarding.assignments.newDeadline') }}</label>
      <DatePicker
        v-model="newDate"
        date-format="dd.mm.yy"
        show-icon
        :min-date="today"
        class="w-100"
      />
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        outlined
        @click="visible = false"
      />
      <Button
        :label="t('common.save')"
        icon="pi pi-check"
        :loading="saving"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import type { CourseAssignment } from '@/entities/assignment'

const props = defineProps<{
  assignment: CourseAssignment | null
}>()

const emit = defineEmits<{
  save: [dueDate: string | null]
}>()

const { t } = useI18n()
const visible = defineModel<boolean>('visible', { default: false })
const saving = ref(false)
const newDate = ref<Date | null>(null)
const today = new Date()

watch(
  () => props.assignment,
  (a) => {
    newDate.value = a?.due_date ? new Date(a.due_date) : null
  },
)

function submit(): void {
  saving.value = true
  emit('save', newDate.value ? newDate.value.toISOString().slice(0, 10) : null)
  saving.value = false
}
</script>

<style lang="scss" scoped>
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
</style>
