<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="!isPending"
    :draggable="false"
    :style="{ width: '520px', maxWidth: '95vw' }"
    class="system-reset-dialog"
    @hide="onHide"
  >
    <template #header>
      <div class="system-reset-dialog__header">
        <i class="pi pi-exclamation-triangle system-reset-dialog__warn-icon" aria-hidden="true" />
        <span class="system-reset-dialog__title">{{ t('system.reset.dialog_title') }}</span>
      </div>
    </template>

    <div class="system-reset-dialog__body">
      <!-- What will be deleted -->
      <div class="system-reset-section system-reset-section--danger">
        <p class="system-reset-section__label">
          <i class="pi pi-trash" aria-hidden="true" />
          {{ t('system.reset.will_delete_title') }}
        </p>
        <ul class="system-reset-list">
          <li>{{ t('system.reset.will_delete.deals') }}</li>
          <li>{{ t('system.reset.will_delete.contacts') }}</li>
          <li>{{ t('system.reset.will_delete.documents') }}</li>
          <li>{{ t('system.reset.will_delete.activities') }}</li>
          <li>{{ t('system.reset.will_delete.automations') }}</li>
          <li>{{ t('system.reset.will_delete.onboarding') }}</li>
          <li>{{ t('system.reset.will_delete.notifications') }}</li>
          <li>{{ t('system.reset.will_delete.custom_fields') }}</li>
        </ul>
      </div>

      <!-- What will stay -->
      <div class="system-reset-section system-reset-section--safe">
        <p class="system-reset-section__label">
          <i class="pi pi-check-circle" aria-hidden="true" />
          {{ t('system.reset.will_keep_title') }}
        </p>
        <ul class="system-reset-list">
          <li>{{ t('system.reset.will_keep.accounts') }}</li>
          <li>{{ t('system.reset.will_keep.roles') }}</li>
          <li>{{ t('system.reset.will_keep.pipelines') }}</li>
          <li>{{ t('system.reset.will_keep.catalog') }}</li>
          <li>{{ t('system.reset.will_keep.approval_routes') }}</li>
          <li>{{ t('system.reset.will_keep.lost_reasons') }}</li>
        </ul>
      </div>

      <!-- Re-login notice -->
      <Message severity="warn" :closable="false" class="mt-3">
        {{ t('system.reset.relogin_notice') }}
      </Message>

      <!-- Confirm phrase input -->
      <div class="system-reset-confirm mt-4">
        <label class="system-reset-confirm__label" :for="inputId">
          {{ t('system.reset.confirm_label', { phrase: RESET_CONFIRM_PHRASE }) }}
        </label>
        <InputText
          :id="inputId"
          v-model="confirmInput"
          :placeholder="RESET_CONFIRM_PHRASE"
          :disabled="isPending"
          :invalid="confirmInput.length > 0 && !isConfirmed"
          class="w-100 mt-2 system-reset-confirm__input"
          autocomplete="off"
          spellcheck="false"
        />
      </div>
    </div>

    <template #footer>
      <div class="d-flex justify-content-end gap-3">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          outlined
          :disabled="isPending"
          @click="onCancel"
        />
        <Button
          :label="isPending ? t('system.reset.resetting') : t('system.reset.confirm_btn')"
          severity="danger"
          :disabled="!isConfirmed || isPending"
          :loading="isPending"
          @click="onConfirm"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'

interface Props {
  visible: boolean
  confirmInput: string
  isConfirmed: boolean
  isPending: boolean
  RESET_CONFIRM_PHRASE: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'update:confirmInput', value: string): void
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const inputId = 'system-reset-phrase-input'

const visible = computed({
  get: () => props.visible,
  set: (val) => emit('update:visible', val),
})

const confirmInput = computed({
  get: () => props.confirmInput,
  set: (val) => emit('update:confirmInput', val),
})

function onCancel() {
  emit('cancel')
}

function onConfirm() {
  emit('confirm')
}

function onHide() {
  if (!props.isPending) {
    emit('cancel')
  }
}
</script>

<style lang="scss" scoped>
.system-reset-dialog {
  &__header {
    display: flex;
    align-items: center;
    gap: $space-3;
  }

  &__warn-icon {
    font-size: 1.25rem;
    color: var(--p-red-500);
    flex-shrink: 0;
  }

  &__title {
    font-size: $font-size-lg;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }

  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-3;
  }
}

.system-reset-section {
  padding: $space-3 $space-4;
  border-radius: $radius-md;
  border: 1px solid;

  &--danger {
    background-color: rgba(var(--p-red-500-rgb, 239 68 68), 0.06);
    border-color: rgba(var(--p-red-500-rgb, 239 68 68), 0.25);
  }

  &--safe {
    background-color: rgba(var(--p-green-500-rgb, 34 197 94), 0.06);
    border-color: rgba(var(--p-green-500-rgb, 34 197 94), 0.25);
  }

  &__label {
    display: flex;
    align-items: center;
    gap: $space-2;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    margin: 0 0 $space-2;

    i {
      font-size: 0.9rem;

      .system-reset-section--danger & {
        color: var(--p-red-500);
      }

      .system-reset-section--safe & {
        color: var(--p-green-500);
      }
    }
  }
}

.system-reset-list {
  margin: 0;
  padding-left: $space-5;
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.system-reset-confirm {
  &__label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    line-height: 1.5;
  }

  &__input {
    font-family: monospace;
    font-size: $font-size-sm;
    letter-spacing: 0.05em;
  }
}
</style>
