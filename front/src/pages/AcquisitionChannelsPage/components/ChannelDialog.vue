<template>
  <Dialog
    v-model:visible="visible"
    :header="editing ? t('admin.acquisitionChannels.edit') : t('admin.acquisitionChannels.add')"
    modal
    :style="{ width: '26rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Name -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.acquisitionChannels.fields.name') }}</label>
        <InputText
          v-model="form.name"
          class="w-100 mt-1"
          :class="{ 'p-invalid': nameError }"
          autofocus
        />
        <small v-if="nameError" class="p-error">{{ t('common.required') }}</small>
      </div>

      <!-- Sort order -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.acquisitionChannels.fields.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-100 mt-1" />
      </div>

      <!-- Is active -->
      <div class="col-12 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 dir-dialog__label">{{ t('admin.acquisitionChannels.fields.isActive') }}</label>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="cancel" />
      <Button :label="t('common.save')" :loading="loading" @click="submit" />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import ToggleSwitch from 'primevue/toggleswitch'
import type { AcquisitionChannel } from '@/entities/crm'
import type { ChannelFormPayload } from '../composables/useAcquisitionChannelsPage'

const props = defineProps<{
  modelValue: boolean
  editing: AcquisitionChannel | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: ChannelFormPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = ref<ChannelFormPayload>({
  name: '',
  sort_order: 0,
  is_active: true,
})

const nameError = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      nameError.value = false
      if (props.editing) {
        form.value = {
          name: props.editing.name,
          sort_order: props.editing.sort_order,
          is_active: props.editing.is_active,
        }
      } else {
        form.value = { name: '', sort_order: 0, is_active: true }
      }
    }
  },
)

function cancel() {
  visible.value = false
}

function submit() {
  nameError.value = !form.value.name.trim()
  if (nameError.value) return
  emit('save', { ...form.value })
  // Dialog is closed by the parent composable's onSuccess handler after the API responds.
  // Do NOT close here — closing optimistically races against the async mutation
  // and the parent's prop (dialogVisible) would not yet be false, causing a re-render
  // that re-opens the dialog when isPending flips.
}
</script>

<style lang="scss" scoped>
.dir-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
