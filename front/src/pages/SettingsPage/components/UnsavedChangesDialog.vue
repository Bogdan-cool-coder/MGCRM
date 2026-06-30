<template>
  <Dialog
    v-model:visible="visible"
    :header="t('settings.dirtyGuard.title')"
    :modal="true"
    :closable="false"
    :draggable="false"
    :style="{ width: '380px' }"
    append-to="body"
    class="unsaved-changes-dialog"
  >
    <p class="unsaved-changes-dialog__message">{{ t('settings.dirtyGuard.message') }}</p>
    <template #footer>
      <div class="unsaved-changes-dialog__footer">
        <Button
          :label="t('settings.dirtyGuard.stay')"
          severity="secondary"
          outlined
          @click="onStay"
        />
        <Button
          :label="t('settings.dirtyGuard.leave')"
          severity="danger"
          @click="onLeave"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const { t } = useI18n()

const visible = defineModel<boolean>('visible', { required: true })

const emit = defineEmits<{
  (e: 'leave'): void
  (e: 'stay'): void
}>()

function onLeave() {
  visible.value = false
  emit('leave')
}

function onStay() {
  visible.value = false
  emit('stay')
}
</script>

<style lang="scss" scoped>
.unsaved-changes-dialog {
  &__message {
    margin: 0;
    font-size: $font-size-sm;
    color: $surface-700;
    line-height: $line-height-normal;

    .app-dark & {
      color: var(--p-surface-300);
    }
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}
</style>
