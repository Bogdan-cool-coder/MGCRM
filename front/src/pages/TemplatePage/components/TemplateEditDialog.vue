<template>
  <Dialog
    v-model:visible="visible"
    :header="t('templates.card.edit')"
    :style="{ width: '460px' }"
    modal
    :draggable="false"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="template-edit-dialog__label">{{ t('templates.card.meta.title', 'Название') }}</label>
        <InputText v-model="form.title" class="w-100 mt-1" />
      </div>

      <div>
        <label class="template-edit-dialog__label">{{ t('templates.card.meta.products', 'Продукты') }}</label>
        <InputText
          v-model="productCodesRaw"
          class="w-100 mt-1"
          :placeholder="t('templates.card.edit.placeholderCodes', 'macrocrm, macrosales (через запятую)')"
        />
        <small class="text-secondary">{{ t('templates.card.edit.codesHint', 'Пустое поле = wildcard (все продукты)') }}</small>
      </div>

      <div>
        <label class="template-edit-dialog__label">{{ t('templates.card.meta.countries', 'Страны') }}</label>
        <InputText
          v-model="countryCodesRaw"
          class="w-100 mt-1"
          :placeholder="t('templates.card.edit.placeholderCodes', 'kz, uz')"
        />
        <small class="text-secondary">{{ t('templates.card.edit.codesHint', 'Пустое поле = wildcard (все страны)') }}</small>
      </div>
    </div>

    <template #footer>
      <div class="d-flex gap-2 justify-content-end">
        <Button
          :label="t('common.cancel', 'Отмена')"
          severity="secondary"
          text
          @click="visible = false"
        />
        <Button
          :label="t('common.save', 'Сохранить')"
          :loading="saving"
          @click="save"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import type { TemplateDto } from '@/entities/template'

const props = defineProps<{
  modelValue: boolean
  template: TemplateDto | null
  saving: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: { title: string; product_codes: string[]; country_codes: string[] }]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = ref({ title: '' })
const productCodesRaw = ref('')
const countryCodesRaw = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open && props.template) {
      form.value.title = props.template.title
      productCodesRaw.value = props.template.product_codes.join(', ')
      countryCodesRaw.value = props.template.country_codes.join(', ')
    }
  },
)

function parseCodes(raw: string): string[] {
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

function save() {
  emit('save', {
    title: form.value.title,
    product_codes: parseCodes(productCodesRaw.value),
    country_codes: parseCodes(countryCodesRaw.value),
  })
}
</script>

<style lang="scss" scoped>
.template-edit-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
