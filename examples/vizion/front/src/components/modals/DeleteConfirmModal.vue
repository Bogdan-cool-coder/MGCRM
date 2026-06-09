<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="title"
    :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    :closable="true"
  >
    <div class="delete-confirm">
      <i :class="icon" :style="{ color: iconColor }" class="confirm-icon"></i>
      <p class="confirm-message">
        <slot name="message">
          {{ t('deletePrompt') }}
          <strong>{{ itemName || t('deleteFallbackItem') }}</strong>?
        </slot>
      </p>
      <p v-if="showWarning" class="confirm-warning">{{ warningText }}</p>
    </div>

    <template #footer>
      <Button
        :label="cancelLabel"
        severity="secondary"
        @click="$emit('cancel')"
        :disabled="loading"
      />
      <Button
        :label="confirmLabel"
        :severity="confirmSeverity"
        :loading="loading"
        @click="$emit('confirm')"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/modals/locale/en.json'
import ru from '@/components/modals/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  visible: boolean
  title?: string
  itemName?: string
  loading?: boolean
  width?: string
  icon?: string
  iconColor?: string
  cancelLabel?: string
  confirmLabel?: string
  confirmSeverity?:
    | 'primary'
    | 'secondary'
    | 'danger'
    | 'success'
    | 'warning'
    | 'help'
    | 'info'
    | 'contrast'
  showWarning?: boolean
  warningText?: string
}

const props = withDefaults(defineProps<Props>(), {
  title: undefined,
  icon: 'pi pi-exclamation-triangle',
  iconColor: '$red-500',
  cancelLabel: undefined,
  confirmLabel: undefined,
  confirmSeverity: 'danger',
  showWarning: true,
  warningText: undefined,
})

const visible = computed({
  get: () => props.visible,
  set: (value) => emit('update:visible', value),
})

interface Emits {
  (e: 'update:visible', value: boolean): void
  (e: 'cancel'): void
  (e: 'confirm'): void
}

const emit = defineEmits<Emits>()

const title = computed(() => props.title || t('deleteTitle'))
const cancelLabel = computed(() => props.cancelLabel || t('common.cancel'))
const confirmLabel = computed(() => props.confirmLabel || t('common.delete'))
const warningText = computed(() => props.warningText || t('deleteWarning'))
</script>

<style lang="scss" scoped>
.delete-confirm {
  text-align: center;

  .confirm-icon {
    font-size: $font-size-4xl;
    margin-bottom: 1rem;
    display: block;
  }

  .confirm-message {
    margin: 0 0 0.5rem;
    font-size: $font-size-md;
    color: $surface-700;

    strong {
      color: $surface-900;
    }
  }

  .confirm-warning {
    margin: 0;
    font-size: $font-size-sm;
    color: $surface-500;
  }
}
</style>
