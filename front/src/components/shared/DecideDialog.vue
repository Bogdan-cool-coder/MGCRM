<template>
  <Dialog
    v-model:visible="visible"
    :header="t('documents.approval.decide.title', 'Решение по документу')"
    modal
    :style="{ width: '28rem' }"
    :draggable="false"
    class="decide-dialog"
  >
    <div class="decide-dialog__body">
      <div class="mb-3">
        <label class="decide-dialog__label">
          {{ t('documents.approval.decide.commentPlaceholder') }}
          <span v-if="required" class="text-danger ms-1">*</span>
        </label>
        <Textarea
          v-model="comment"
          :placeholder="t('documents.approval.decide.commentPlaceholder')"
          :rows="4"
          autoResize
          class="w-100 mt-1"
          :class="{ 'p-invalid': showError }"
        />
        <small v-if="showError" class="p-error">
          {{ t('documents.approval.decide.commentRequired') }}
        </small>
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="cancel"
      />
      <Button
        :label="t('common.confirm')"
        :loading="loading"
        @click="confirm"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'

const props = withDefaults(defineProps<{
  modelValue: boolean
  loading?: boolean
  /** If true, comment is required (reject/needs_rework) */
  required?: boolean
}>(), {
  loading: false,
  required: true,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: [comment: string]
}>()

const { t } = useI18n()

const comment = ref('')
const showError = ref(false)

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      comment.value = ''
      showError.value = false
    }
  },
)

function cancel() {
  visible.value = false
}

function confirm() {
  if (props.required && !comment.value.trim()) {
    showError.value = true
    return
  }
  emit('confirm', comment.value.trim())
}
</script>

<style lang="scss" scoped>
.decide-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
