<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deals.page.bulk.moveStageDialog.title', { n: dealIds.length })"
    modal
    style="width: 420px"
    :closable="!saving"
    class="bulk-move-dialog"
  >
    <div class="bulk-move-dialog__body">
      <div class="bulk-move-dialog__field">
        <label class="bulk-move-dialog__label">
          {{ t('sales.deals.page.bulk.moveStageDialog.stage') }}
          <span class="req">*</span>
        </label>
        <Select
          v-model="selectedStageId"
          :options="effectiveStages"
          option-label="name"
          option-value="id"
          class="w-full"
          :class="{ 'p-invalid': hasError }"
          :placeholder="t('sales.deals.page.bulk.moveStageDialog.stagePlaceholder')"
          :loading="loadingStages"
        />
        <small v-if="hasError" class="p-error">
          {{ t('sales.deals.page.bulk.moveStageDialog.stageRequired') }}
        </small>
      </div>
    </div>

    <template #footer>
      <div class="bulk-move-dialog__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.page.bulk.moveStageDialog.apply')"
          :loading="saving"
          @click="onSubmit"
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
import Select from 'primevue/select'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { useSalesStore } from '@/stores/salesStore'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  modelValue: boolean
  dealIds: number[]
  stages: PipelineStageDto[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  done: []
}>()

const { t } = useI18n()
const salesStore = useSalesStore()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selectedStageId = ref<number | null>(null)
const hasError = ref(false)
const localStages = ref<PipelineStageDto[]>([])
const loadingStages = ref(false)

// Use prop stages if available, otherwise fall back to locally loaded stages
const effectiveStages = computed<PipelineStageDto[]>(() =>
  props.stages.length > 0 ? props.stages : localStages.value,
)

const mutation = useMutation()
const saving = computed(() => mutation.isPending.value)

async function ensureStages() {
  if (props.stages.length > 0) return
  // Stages not passed — load from active pipeline
  const pid = salesStore.activePipelineId
  if (!pid) return
  // Check cache first
  const cached = salesStore.getCachedStages(pid)
  if (cached.length > 0) {
    localStages.value = cached
    return
  }
  loadingStages.value = true
  try {
    const pipelines = await salesApi.getPipelines('sales')
    const pipeline = pipelines.find((p) => p.id === pid) ?? pipelines[0]
    if (pipeline?.stages) {
      localStages.value = pipeline.stages
      salesStore.cacheStages(pipeline.id, pipeline.stages)
    }
  } finally {
    loadingStages.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      selectedStageId.value = null
      hasError.value = false
      void ensureStages()
    }
  },
)

async function onSubmit() {
  if (!selectedStageId.value) {
    hasError.value = true
    return
  }
  hasError.value = false

  await mutation.run(() =>
    salesApi.bulkPatchDeals({
      deal_ids: props.dealIds,
      operation: 'change_stage',
      stage_id: selectedStageId.value,
    }),
  )

  visible.value = false
  emit('done')
}
</script>

<style lang="scss" scoped>
.bulk-move-dialog {
  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-2 0;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
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
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
