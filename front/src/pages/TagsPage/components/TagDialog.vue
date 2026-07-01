<template>
  <Dialog
    v-model:visible="visible"
    :header="editing ? t('admin.tags.edit') : t('admin.tags.add')"
    modal
    :style="{ width: '28rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Name -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.tags.fields.name') }}</label>
        <InputText
          v-model="form.name"
          class="w-100 mt-1"
          :class="{ 'p-invalid': nameError }"
          maxlength="64"
          autofocus
        />
        <small v-if="nameError" class="p-error">{{ t('common.required') }}</small>
      </div>

      <!-- Color -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.tags.fields.color') }}</label>
        <div class="tag-dialog__color-row mt-1">
          <!-- Swatch preview -->
          <span
            class="tag-dialog__color-swatch"
            :style="colorStyle"
            :title="form.color ?? t('admin.tags.fields.colorNone')"
          />
          <InputText
            v-model="form.color"
            class="tag-dialog__color-input"
            :class="{ 'p-invalid': colorError }"
            placeholder="#RRGGBB"
            maxlength="7"
            @input="onColorInput"
          />
          <Button
            v-if="form.color"
            icon="pi pi-times"
            text
            severity="secondary"
            size="small"
            :title="t('admin.tags.fields.colorClear')"
            @click="form.color = null"
          />
        </div>
        <small v-if="colorError" class="p-error">{{ t('admin.tags.errors.colorFormat') }}</small>
      </div>

      <!-- Scope -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.tags.fields.scope') }}</label>
        <Select
          v-model="form.scope"
          :options="scopeOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
          :placeholder="t('admin.tags.fields.scopePlaceholder')"
        />
      </div>

      <!-- Sort order -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.tags.fields.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-100 mt-1" />
      </div>

      <!-- Is active -->
      <div class="col-12 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 dir-dialog__label">{{ t('admin.tags.fields.isActive') }}</label>
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
import Select from 'primevue/select'
import type { Tag, TagScope } from '@/entities/crm'
import type { TagFormPayload } from '../composables/useTagsPage'

const props = defineProps<{
  modelValue: boolean
  editing: Tag | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: TagFormPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const HEX_RE = /^#[0-9a-fA-F]{6}$/

const scopeOptions = computed(() => [
  { label: t('admin.tags.scopes.all'), value: null },
  { label: t('admin.tags.scopes.deal'), value: 'deal' as TagScope },
  { label: t('admin.tags.scopes.contact'), value: 'contact' as TagScope },
  { label: t('admin.tags.scopes.company'), value: 'company' as TagScope },
])

const form = ref<TagFormPayload>({
  name: '',
  color: null,
  scope: null,
  sort_order: 0,
  is_active: true,
})

const nameError = ref(false)
const colorError = ref(false)

const colorStyle = computed(() => {
  const c = form.value.color
  if (c && HEX_RE.test(c)) {
    return { background: c }
  }
  return {}
})

function onColorInput() {
  // Ensure prefix
  if (form.value.color && !form.value.color.startsWith('#')) {
    form.value.color = `#${form.value.color}`
  }
  colorError.value = !!form.value.color && !HEX_RE.test(form.value.color)
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      nameError.value = false
      colorError.value = false
      if (props.editing) {
        form.value = {
          name: props.editing.name,
          color: props.editing.color,
          scope: props.editing.scope,
          sort_order: props.editing.sort_order,
          is_active: props.editing.is_active,
        }
      } else {
        form.value = { name: '', color: null, scope: null, sort_order: 0, is_active: true }
      }
    }
  },
)

function cancel() {
  emit('update:modelValue', false)
}

function submit() {
  nameError.value = !form.value.name.trim()
  colorError.value = !!form.value.color && !HEX_RE.test(form.value.color)
  if (nameError.value || colorError.value) return
  emit('save', {
    ...form.value,
    name: form.value.name.trim(),
    color: form.value.color || null,
  })
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

.tag-dialog {
  &__color-row {
    display: flex;
    align-items: center;
    gap: $space-2;
  }

  &__color-swatch {
    display: inline-block;
    width: 28px;
    height: 28px;
    border-radius: $radius-sm;
    border: 1px solid var(--p-surface-200);
    flex-shrink: 0;
    background: var(--p-surface-100);

    .app-dark & {
      border-color: var(--p-surface-600);
      background: var(--p-surface-700);
    }
  }

  &__color-input {
    flex: 1;
    font-family: $font-family-mono;
    font-size: $font-size-sm;
  }
}
</style>
