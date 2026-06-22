<template>
  <div class="context-tab">
    <!-- Autosave indicator -->
    <div class="context-tab__autosave mb-3">
      <span v-if="autosaveState === 'saved'" class="context-tab__autosave--saved">
        <i class="pi pi-check me-1" />{{ t('documents.card.autosave.saved') }}
      </span>
      <span v-else-if="autosaveState === 'saving'" class="context-tab__autosave--saving">
        <ProgressSpinner style="width: 14px; height: 14px;" class="me-1" stroke-width="6" />
        {{ t('documents.card.autosave.saving') }}
      </span>
      <span v-else-if="autosaveState === 'error'" class="context-tab__autosave--error">
        <i class="pi pi-exclamation-triangle me-1" />{{ t('documents.card.autosave.error') }}
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loadingVariables">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
    </div>

    <!-- Dynamic form -->
    <template v-else>
      <template v-for="(group, groupName) in groupedVariables" :key="groupName">
        <p v-if="groupName" class="context-tab__group-label fw-semibold mb-2">
          {{ groupName }}
        </p>

        <div class="row g-3 mb-3">
          <div
            v-for="variable in group"
            :key="variable.id"
            class="col-md-6"
          >
            <label class="context-tab__field-label">
              {{ variable.label }}
              <span v-if="variable.required" class="text-danger ms-1">*</span>
              <small v-if="variable.help_text" class="text-secondary ms-1">
                ({{ variable.help_text }})
              </small>
            </label>

            <!-- text -->
            <InputText
              v-if="variable.var_type === 'text'"
              v-model="(contextModel as Record<string, string>)[variable.key]"
              :disabled="!canEdit"
              class="w-100 mt-1"
              @update:model-value="onFieldChange"
            />

            <!-- textarea -->
            <Textarea
              v-else-if="variable.var_type === 'textarea'"
              v-model="(contextModel as Record<string, string>)[variable.key]"
              :disabled="!canEdit"
              :rows="3"
              auto-resize
              class="w-100 mt-1"
              @update:model-value="onFieldChange"
            />

            <!-- number -->
            <InputNumber
              v-else-if="variable.var_type === 'number'"
              v-model="(contextModel as Record<string, unknown>)[variable.key] as number"
              :disabled="!canEdit"
              :min-fraction-digits="0"
              :max-fraction-digits="2"
              class="w-100 mt-1"
              @update:model-value="onFieldChange"
            />

            <!-- date -->
            <DatePicker
              v-else-if="variable.var_type === 'date'"
              v-model="(contextModel as Record<string, unknown>)[variable.key] as Date"
              :disabled="!canEdit"
              date-format="dd.mm.yy"
              show-icon
              class="w-100 mt-1"
              @update:model-value="onFieldChange"
            />

            <!-- select -->
            <Select
              v-else-if="variable.var_type === 'select'"
              v-model="contextModel[variable.key]"
              :options="variable.options"
              option-label="name"
              option-value="value"
              :disabled="!canEdit"
              class="w-100 mt-1"
              @update:model-value="onFieldChange"
            />

            <!-- checkbox -->
            <div v-else-if="variable.var_type === 'checkbox'" class="d-flex align-items-center gap-2 mt-1">
              <Checkbox
                v-model="(contextModel as Record<string, unknown>)[variable.key] as boolean"
                :disabled="!canEdit"
                :binary="true"
                :input-id="`ctx-${variable.key}`"
                @update:model-value="onFieldChange"
              />
              <label :for="`ctx-${variable.key}`" class="mb-0 context-tab__field-label">
                {{ variable.label }}
              </label>
            </div>
          </div>
        </div>
      </template>

      <div v-if="variables.length === 0" class="context-tab__empty">
        <i class="pi pi-list context-tab__empty-icon" />
        <p>{{ t('documents.card.tabs.context') }}</p>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, watch, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Skeleton from 'primevue/skeleton'
import ProgressSpinner from 'primevue/progressspinner'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { templateVariablesApi } from '@/api/templateVariables'
import type { TemplateVariableDto } from '@/entities/templateVariable'

type AutosaveState = 'idle' | 'saving' | 'saved' | 'error'

const props = defineProps<{
  productCode: string | null
  countryCode: string | null
  initialContext: Record<string, unknown> | null
  canEdit: boolean
  autosaveState: AutosaveState
}>()

const emit = defineEmits<{
  contextChange: [context: Record<string, unknown>]
}>()

const { t } = useI18n()

// ─── Variables load ────────────────────────────────────────────────────────
const variablesResource = useAsyncResource<TemplateVariableDto[]>(() => [])
const variables = computed(() => variablesResource.data.value)
const loadingVariables = computed(() => variablesResource.loading.value)

watch(
  [() => props.productCode, () => props.countryCode],
  async ([product, country]) => {
    if (!product && !country) return
    await variablesResource.run(() =>
      templateVariablesApi.getTemplateVariables({
        product_codes: product ? [product] : undefined,
        country_codes: country ? [country] : undefined,
        is_active: true,
      }),
    )
  },
  { immediate: true },
)

// ─── Context model (reactive) ──────────────────────────────────────────────
const contextModel = reactive<Record<string, unknown>>({})

watch(
  () => props.initialContext,
  (ctx) => {
    if (ctx) {
      Object.assign(contextModel, ctx)
    }
  },
  { immediate: true },
)

function onFieldChange() {
  emit('contextChange', { ...contextModel })
}

// ─── Grouped variables ─────────────────────────────────────────────────────
const groupedVariables = computed(() => {
  const groups: Record<string, TemplateVariableDto[]> = {}
  for (const v of variables.value) {
    const key = v.group ?? ''
    if (!groups[key]) groups[key] = []
    groups[key].push(v)
  }
  // Sort by sort_order
  for (const key of Object.keys(groups)) {
    groups[key]!.sort((a, b) => a.sort_order - b.sort_order)
  }
  return groups
})
</script>

<style lang="scss" scoped>
.context-tab {
  &__autosave {
    min-height: 20px;
    font-size: $font-size-sm;

    &--saved {
      color: var(--p-green-500);
    }

    &--saving {
      color: var(--p-text-muted-color);
      display: inline-flex;
      align-items: center;
    }

    &--error {
      color: var(--p-red-500);
    }
  }

  &__group-label {
    color: var(--p-text-color);
    font-size: $font-size-sm;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid var(--p-surface-200);
    padding-bottom: $space-1;
  }

  &__field-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    padding: $space-6;
    color: var(--p-text-muted-color);
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    opacity: 0.3;
  }
}
</style>
