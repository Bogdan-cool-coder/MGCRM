<template>
  <Dialog
    v-model:visible="visible"
    :header="header"
    modal
    :style="{ width: '28rem' }"
    :draggable="false"
    class="contacts-delete-dialog"
  >
    <div class="contacts-delete-dialog__body">
      <i class="pi pi-exclamation-triangle contacts-delete-dialog__icon" />
      <p class="contacts-delete-dialog__message">{{ message }}</p>
    </div>

    <template #footer>
      <Button
        :label="t('contacts.page.delete.reject')"
        severity="secondary"
        text
        :disabled="loading"
        @click="visible = false"
      />
      <Button
        :label="t('contacts.page.delete.accept')"
        severity="danger"
        :loading="loading"
        @click="emit('confirm')"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const props = defineProps<{
  modelValue: boolean
  header: string
  message: string
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: []
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})
</script>

<style lang="scss" scoped>
.contacts-delete-dialog__body {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
}

.contacts-delete-dialog__icon {
  font-size: $font-size-icon-sm;
  color: $color-danger;
  flex-shrink: 0;
  margin-top: 2px;
}

.contacts-delete-dialog__message {
  font-size: $font-size-sm;
  color: var(--p-text-color);
  margin: 0;
  line-height: 1.5;
}
</style>
