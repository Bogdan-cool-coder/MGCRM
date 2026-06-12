<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.move.dialog.title')"
    modal
    style="width: 480px"
    :closable="!moving"
  >
    <div class="move-dialog">
      <!-- Current stage -->
      <div class="move-dialog__field">
        <label class="move-dialog__label">{{ t('sales.move.dialog.currentStage') }}</label>
        <DealStageTag :stage="currentStage" />
      </div>

      <!-- New stage select -->
      <div class="move-dialog__field">
        <label class="move-dialog__label">
          {{ t('sales.move.dialog.newStage') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.to_stage_id"
          :options="availableStages"
          option-label="name"
          option-value="id"
          :placeholder="t('sales.move.dialog.newStage')"
          class="w-full"
          @change="onStageSelect"
        />
      </div>

      <!-- Won-gate hint -->
      <Message
        v-if="selectedStage?.won_gate"
        severity="warn"
        size="small"
        :closable="false"
        class="move-dialog__won-hint"
      >
        {{ t('sales.move.dialog.wonGateHint') }}
      </Message>

      <!-- Lost reason (only if is_lost) -->
      <template v-if="selectedStage?.is_lost">
        <div class="move-dialog__field">
          <label class="move-dialog__label">
            {{ t('sales.move.dialog.lostReason') }}
          </label>
          <Select
            v-model="form.lost_reason_id"
            :options="lostReasons"
            option-label="name"
            option-value="id"
            :placeholder="t('sales.move.dialog.lostReason')"
            show-clear
            class="w-full"
            :class="{ 'p-invalid': errors.lost_reason }"
          />
          <small v-if="errors.lost_reason" class="p-error">{{ errors.lost_reason }}</small>
        </div>

        <div class="move-dialog__field">
          <label class="move-dialog__label">{{ t('sales.move.dialog.lostComment') }}</label>
          <Textarea
            v-model="form.lost_reason_text"
            class="w-full"
            rows="2"
            auto-resize
          />
        </div>
      </template>
    </div>

    <template #footer>
      <Button
        :label="t('sales.move.dialog.cancel')"
        severity="secondary"
        text
        :disabled="moving"
        @click="visible = false"
      />
      <Button
        icon="pi pi-arrow-right"
        :label="t('sales.move.dialog.submit')"
        :loading="moving"
        :disabled="!form.to_stage_id || (selectedStage?.is_lost && !form.lost_reason_id && !form.lost_reason_text)"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Textarea from 'primevue/textarea'
import DealStageTag from '../../DealPage/components/DealStageTag.vue'
import { salesApi } from '@/api/sales'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage, getValidationErrors, getApiErrorStatus } from '@/utils/errors'
import type { PipelineStageDto, LostReasonDto, DealDto } from '@/entities/sales'

const props = defineProps<{
  modelValue: boolean
  deal: DealDto
  stages: PipelineStageDto[]
  lostReasons: LostReasonDto[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  moved: [deal: DealDto]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

interface MoveForm {
  to_stage_id: number | null
  lost_reason_id: number | null
  lost_reason_text: string
}

const form = ref<MoveForm>({ to_stage_id: null, lost_reason_id: null, lost_reason_text: '' })
const errors = ref<Record<string, string>>({})

const moveMutation = useMutation<unknown>()
const moving = computed(() => moveMutation.isPending.value)

const currentStage = computed(() => props.deal.stage)

const availableStages = computed(() =>
  props.stages.filter((s) => s.id !== props.deal.stage.id),
)

const selectedStage = computed(() =>
  props.stages.find((s) => s.id === form.value.to_stage_id) ?? null,
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = { to_stage_id: null, lost_reason_id: null, lost_reason_text: '' }
      errors.value = {}
    }
  },
)

function onStageSelect() {
  // Reset lost fields when stage changes
  form.value.lost_reason_id = null
  form.value.lost_reason_text = ''
  errors.value = {}
}

async function onSubmit() {
  if (!form.value.to_stage_id) return
  errors.value = {}

  if (selectedStage.value?.is_lost && !form.value.lost_reason_id && !form.value.lost_reason_text) {
    errors.value.lost_reason = t('sales.move.dialog.errors.lostReasonRequired')
    return
  }

  try {
    const response = await moveMutation.run(() =>
      salesApi.moveDeal(props.deal.id, {
        to_stage_id: form.value.to_stage_id!,
        lost_reason_id: form.value.lost_reason_id ?? undefined,
        lost_reason: form.value.lost_reason_text || undefined,
      }),
    )

    if ((response as unknown as { won_gate_warning?: boolean }).won_gate_warning) {
      toast.add({
        severity: 'warn',
        summary: t('sales.move.dialog.wonGateWarningToast'),
        life: 5000,
      })
    } else {
      toast.add({
        severity: 'success',
        summary: t('sales.move.dialog.successToast', {
          stage: selectedStage.value?.name ?? '',
        }),
        life: 3000,
      })
    }

    visible.value = false
    emit('moved', (response as unknown as { data: DealDto }).data)
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve?.lost_reason_id || ve?.lost_reason) {
        errors.value.lost_reason = ve.lost_reason_id ?? ve.lost_reason ?? ''
        return
      }
    }
    if (status === 403) {
      toast.add({ severity: 'error', summary: t('sales.move.dialog.errors.forbidden'), life: 4000 })
      return
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}
</script>

<style lang="scss" scoped>
.move-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.move-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.move-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.move-dialog__won-hint {
  margin: 0;
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
