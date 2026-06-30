<template>
  <Dialog
    v-model:visible="visible"
    :header="t('admin.users.resetPassword.resultTitle')"
    modal
    :closable="true"
    :draggable="false"
    :style="{ width: '28rem' }"
    @hide="onHide"
  >
    <!-- Warning banner -->
    <div class="rprd__warning">
      <i class="pi pi-exclamation-triangle rprd__warning-icon" aria-hidden="true" />
      <span>{{ t('admin.users.resetPassword.oneTimeWarning') }}</span>
    </div>

    <!-- Password display -->
    <div class="rprd__password-block">
      <label class="rprd__label">{{ t('admin.users.resetPassword.newPassword') }}</label>
      <div class="rprd__password-row">
        <code class="rprd__password-value" aria-live="polite">{{ generatedPassword }}</code>
        <Button
          :icon="copied ? 'pi pi-check' : 'pi pi-copy'"
          :severity="copied ? 'success' : 'secondary'"
          text
          rounded
          size="small"
          :aria-label="t('admin.users.resetPassword.copy')"
          v-tooltip.top="t('admin.users.resetPassword.copy')"
          @click="copyPassword"
        />
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('common.close')"
        severity="secondary"
        outlined
        @click="visible = false"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const { t } = useI18n()

const props = defineProps<{
  modelValue: boolean
  password: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const visible = ref(props.modelValue)
const copied = ref(false)
// Local shadow — cleared on hide, never sent to store or console
const generatedPassword = ref(props.password)

watch(
  () => props.modelValue,
  (v) => {
    visible.value = v
    if (v) {
      generatedPassword.value = props.password
      copied.value = false
    }
  },
)

watch(visible, (v) => {
  emit('update:modelValue', v)
})

function onHide() {
  // Clear from local memory immediately when dialog closes
  generatedPassword.value = ''
  copied.value = false
}

let copyTimeout: ReturnType<typeof setTimeout> | null = null

function copyPassword() {
  if (!generatedPassword.value) return
  void navigator.clipboard.writeText(generatedPassword.value).then(() => {
    copied.value = true
    if (copyTimeout !== null) clearTimeout(copyTimeout)
    copyTimeout = setTimeout(() => {
      copied.value = false
      copyTimeout = null
    }, 2000)
  })
}
</script>

<style lang="scss" scoped>
.rprd {
  &__warning {
    display: flex;
    align-items: flex-start;
    gap: $space-2;
    padding: $space-3;
    margin-bottom: $space-4;
    background: var(--p-yellow-50);
    border: 1px solid var(--p-yellow-200);
    border-radius: $radius-md;
    font-size: $font-size-sm;
    color: var(--p-yellow-900);
    line-height: $line-height-normal;

    .app-dark & {
      background: var(--p-surface-50);
      border-color: var(--p-surface-200);
      color: var(--p-yellow-200);
    }
  }

  &__warning-icon {
    font-size: $font-size-base;
    flex-shrink: 0;
    margin-top: 1px;
    color: var(--p-yellow-500);

    .app-dark & {
      color: var(--p-yellow-400);
    }
  }

  &__password-block {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
  }

  &__password-row {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-3;
    background: var(--p-surface-100);
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;

    .app-dark & {
      background: var(--p-surface-50);
      border-color: var(--p-surface-200);
    }
  }

  &__password-value {
    flex: 1;
    font-family: $font-family-mono;
    font-size: $font-size-base;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    letter-spacing: 0.04em;
    word-break: break-all;
    user-select: text;
  }
}
</style>
