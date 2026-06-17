<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 500px"
    @hide="onHide"
  >
    <template #header>
      <div class="product-drawer__header">
        <span class="product-drawer__header-title">
          {{ isEdit ? t('catalog.product.form.editTitle') : t('catalog.product.form.createTitle') }}
        </span>
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          rounded
          :disabled="saving"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>
    <div class="product-drawer">
      <!-- Name -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">
          {{ t('catalog.product.form.fields.name') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.name"
          class="w-full"
          :class="{ 'p-invalid': errors.name }"
          :placeholder="t('catalog.product.form.fields.name')"
          @input="onNameInput"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <!-- Code -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">
          {{ t('catalog.product.form.fields.code') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.code"
          class="w-full"
          :class="{ 'p-invalid': errors.code }"
          :placeholder="t('catalog.product.form.fields.code')"
          @input="onCodeManualInput"
        />
        <small v-if="errors.code" class="p-error">{{ errors.code }}</small>
        <small v-else class="p-help">{{ t('catalog.product.form.fields.codeHint') }}</small>
      </div>

      <!-- Description -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">{{ t('catalog.product.form.fields.description') }}</label>
        <Textarea
          v-model="form.description"
          class="w-full"
          rows="3"
          auto-resize
        />
      </div>

      <!-- Group -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">{{ t('catalog.product.form.fields.group') }}</label>
        <Select
          v-model="form.group_id"
          :options="groups"
          option-label="name"
          option-value="id"
          :placeholder="t('catalog.product.form.fields.group')"
          show-clear
          class="w-full"
        />
      </div>

      <!-- Pricing Type -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">
          {{ t('catalog.product.form.fields.pricingType') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.pricing_type"
          :options="pricingTypeOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('catalog.product.form.fields.pricingType')"
          class="w-full"
          :class="{ 'p-invalid': errors.pricing_type }"
        />
        <small v-if="errors.pricing_type" class="p-error">{{ errors.pricing_type }}</small>
      </div>

      <!-- Template Code -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">{{ t('catalog.product.form.fields.templateCode') }}</label>
        <InputText
          v-model="form.maps_to_product_code"
          class="w-full"
          :placeholder="t('catalog.product.form.fields.templateCode')"
        />
      </div>

      <!-- Sort Order -->
      <div class="product-drawer__field">
        <label class="product-drawer__label">{{ t('catalog.product.form.fields.sortOrder') }}</label>
        <InputNumber
          v-model="form.sort_order"
          :min="0"
          :max="9999"
          class="w-full"
        />
      </div>

      <!-- Is Active -->
      <div class="product-drawer__field product-drawer__field--row">
        <label class="product-drawer__label">{{ t('catalog.product.form.fields.isActive') }}</label>
        <ToggleSwitch v-model="form.is_active" />
      </div>
    </div>

    <template #footer>
      <div class="product-drawer__footer">
        <Button
          :label="t('catalog.product.form.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="onCancel"
        />
        <Button
          icon="pi pi-check"
          :label="isEdit ? t('catalog.product.form.update') : t('catalog.product.form.save')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import ToggleSwitch from 'primevue/toggleswitch'
import Button from 'primevue/button'
import { catalogApi } from '@/api/catalog'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import type { ProductDto, ProductGroupDto } from '@/entities/catalog'

const props = defineProps<{
  modelValue: boolean
  editProduct?: ProductDto | null
  groups: ProductGroupDto[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [product: ProductDto]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const isEdit = computed(() => !!props.editProduct)

interface ProductForm {
  name: string
  code: string
  description: string
  group_id: number | null
  pricing_type: string
  maps_to_product_code: string
  sort_order: number
  is_active: boolean
}

const defaultForm = (): ProductForm => ({
  name: '',
  code: '',
  description: '',
  group_id: null,
  pricing_type: '',
  maps_to_product_code: '',
  sort_order: 0,
  is_active: true,
})

const form = ref<ProductForm>(defaultForm())
const errors = ref<Record<string, string>>({})
const codeManuallyEdited = ref(false)

const mutation = useMutation<ProductDto>()
const saving = computed(() => mutation.isPending.value)

const pricingTypeOptions = computed(() => [
  { value: 'fixed', label: t('catalog.products.pricingType.fixed') },
  { value: 'tiered', label: t('catalog.products.pricingType.tiered') },
  { value: 'per_minute', label: t('catalog.products.pricingType.per_minute') },
  { value: 'package', label: t('catalog.products.pricingType.package') },
  { value: 'custom', label: t('catalog.products.pricingType.custom') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      errors.value = {}
      codeManuallyEdited.value = false
      if (props.editProduct) {
        form.value = {
          name: props.editProduct.name,
          code: props.editProduct.code,
          description: props.editProduct.description ?? '',
          group_id: props.editProduct.group_id,
          pricing_type: props.editProduct.pricing_type,
          maps_to_product_code: props.editProduct.maps_to_product_code ?? '',
          sort_order: props.editProduct.sort_order,
          is_active: props.editProduct.is_active,
        }
        codeManuallyEdited.value = true
      } else {
        form.value = defaultForm()
      }
    }
  },
)

function toSlug(value: string): string {
  const ruMap: Record<string, string> = {
    а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'yo',ж:'zh',з:'z',и:'i',й:'y',
    к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',
    х:'h',ц:'ts',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya',
  }
  return value
    .toLowerCase()
    .split('')
    .map((c) => ruMap[c] ?? c)
    .join('')
    .replace(/[^a-z0-9_]/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '')
}

function onNameInput() {
  if (!codeManuallyEdited.value) {
    form.value.code = toSlug(form.value.name)
  }
}

function onCodeManualInput() {
  // Sanitize to allowed chars
  form.value.code = form.value.code.toLowerCase().replace(/[^a-z0-9_]/g, '')
  codeManuallyEdited.value = true
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.name || form.value.name.length < 2) {
    errs.name = t('catalog.product.form.errors.nameRequired')
  }
  if (!form.value.code) {
    errs.code = t('catalog.product.form.errors.codeRequired')
  } else if (!/^[a-z0-9_]+$/.test(form.value.code)) {
    errs.code = t('catalog.product.form.errors.codeInvalid')
  }
  if (!form.value.pricing_type) {
    errs.pricing_type = t('catalog.product.form.errors.pricingTypeRequired')
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function onSubmit() {
  if (!validate()) return

  const payload = {
    name: form.value.name,
    code: form.value.code,
    description: form.value.description || null,
    group_id: form.value.group_id,
    pricing_type: form.value.pricing_type,
    maps_to_product_code: form.value.maps_to_product_code || null,
    sort_order: form.value.sort_order,
    is_active: form.value.is_active,
  }

  try {
    const product = await mutation.run(() => {
      if (isEdit.value && props.editProduct) {
        return catalogApi.updateProduct(props.editProduct.id, payload)
      }
      return catalogApi.createProduct(payload)
    })

    toast.add({
      severity: 'success',
      summary: isEdit.value
        ? t('catalog.product.form.updateSuccess')
        : t('catalog.product.form.createSuccess'),
      life: 3000,
    })
    emit('saved', product)
    visible.value = false
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const validationErrors = getValidationErrors(err)
      if (validationErrors) {
        errors.value = {
          name: validationErrors.name ?? '',
          code: validationErrors.code?.[0] === 'taken'
            ? t('catalog.product.form.errors.codeTaken')
            : (validationErrors.code ?? ''),
          pricing_type: validationErrors.pricing_type ?? '',
        }
        return
      }
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

function onCancel() {
  visible.value = false
}

function onHide() {
  errors.value = {}
}
</script>

<style lang="scss" scoped>
.product-drawer {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    gap: $space-2;
  }

  &__header-title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;

    &--row {
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
    }
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
    padding-top: $space-4;
    border-top: 1px solid $surface-200;
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}

.p-help {
  font-size: $font-size-xs;
  color: $surface-500;
}

// Dark mode
:global(.app-dark) .p-drawer {
  background: var(--p-surface-card);
}

:deep(.p-drawer-close-button) {
  display: none !important;
}
</style>
