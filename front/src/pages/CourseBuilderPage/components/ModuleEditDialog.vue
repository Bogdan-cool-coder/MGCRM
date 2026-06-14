<template>
  <Dialog
    v-model:visible="visible"
    :header="t('onboarding.builder.module.editTitle')"
    modal
    :style="{ width: '28rem' }"
    :draggable="false"
    @show="onShow"
  >
    <div class="mb-3 pt-2">
      <label class="form-label required">{{ t('onboarding.builder.module.name') }}</label>
      <InputText
        ref="inputRef"
        v-model="title"
        class="w-100"
        :invalid="!title.trim() && submitted"
        @keyup.enter="submit"
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
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import type { CourseModule } from '@/entities/course'

const props = defineProps<{
  editingModule: CourseModule | null
}>()

const emit = defineEmits<{
  save: [title: string]
}>()

const { t } = useI18n()
const visible = defineModel<boolean>('visible', { default: false })

const title = ref('')
const submitted = ref(false)
const saving = ref(false)
const inputRef = ref<InstanceType<typeof InputText> | null>(null)

watch(
  () => props.editingModule,
  (mod) => {
    title.value = mod?.title ?? ''
    submitted.value = false
  },
)

async function onShow(): Promise<void> {
  await nextTick()
  // @ts-expect-error — PrimeVue InputText has $el
  ;(inputRef.value?.$el as HTMLInputElement | null)?.focus()
}

function submit(): void {
  submitted.value = true
  if (!title.value.trim()) return
  saving.value = true
  emit('save', title.value.trim())
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
