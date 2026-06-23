<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.move.dialog.title')"
    modal
    style="width: 460px"
    :closable="!moving"
  >
    <div class="move-deal-dialog">
      <!-- Current stage -->
      <div class="move-deal-dialog__current">
        <span class="move-deal-dialog__section-label">{{ t('sales.move.dialog.currentStage') }}</span>
        <div class="move-deal-dialog__current-stage">
          <span
            class="move-deal-dialog__stage-dot"
            :style="{ background: currentStageColor }"
          />
          <span class="move-deal-dialog__stage-name">{{ props.deal.stage.name }}</span>
        </div>
      </div>

      <!-- New stage: visual list -->
      <div class="move-deal-dialog__new">
        <span class="move-deal-dialog__section-label">{{ t('sales.move.dialog.newStage') }}</span>
        <div class="move-deal-dialog__stage-list">
          <button
            v-for="stage in availableStages"
            :key="stage.id"
            type="button"
            class="move-deal-dialog__stage-item"
            :class="{ 'move-deal-dialog__stage-item--selected': form.to_stage_id === stage.id }"
            @click="selectStage(stage.id)"
          >
            <span
              class="move-deal-dialog__stage-dot"
              :style="{ background: stageColor(stage) }"
            />
            <span class="move-deal-dialog__stage-item-name">{{ stage.name }}</span>
            <i
              v-if="form.to_stage_id === stage.id"
              class="pi pi-check move-deal-dialog__stage-check"
            />
          </button>
        </div>
      </div>

      <!-- Won-gate hint -->
      <Message
        v-if="selectedStage?.won_gate"
        severity="warn"
        size="small"
        :closable="false"
        class="move-deal-dialog__won-hint"
      >
        {{ t('sales.move.dialog.wonGateHint') }}
      </Message>

      <!-- Lost reason (only if is_lost) -->
      <template v-if="selectedStage?.is_lost">
        <div class="move-deal-dialog__field">
          <label class="move-deal-dialog__label">
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

        <div class="move-deal-dialog__field">
          <label class="move-deal-dialog__label">{{ t('sales.move.dialog.lostComment') }}</label>
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

const currentStageColor = computed((): string => {
  const s = currentStage.value
  if (s.color) return s.color
  if (s.is_won) return 'var(--p-green-500)'
  if (s.is_lost) return 'var(--p-red-400)'
  return 'var(--p-primary-400)'
})

const availableStages = computed(() =>
  props.stages
    .filter((s) => s.id !== props.deal.stage.id && !s.is_lost)
    .sort((a, b) => a.sort_order - b.sort_order),
)

const selectedStage = computed(() =>
  props.stages.find((s) => s.id === form.value.to_stage_id) ?? null,
)

function stageColor(stage: PipelineStageDto): string {
  if (stage.color) return stage.color
  if (stage.is_won) return 'var(--p-green-500)'
  if (stage.is_lost) return 'var(--p-red-400)'
  return 'var(--p-primary-400)'
}

function selectStage(id: number) {
  if (form.value.to_stage_id === id) {
    form.value.to_stage_id = null
  } else {
    form.value.to_stage_id = id
    form.value.lost_reason_id = null
    form.value.lost_reason_text = ''
    errors.value = {}
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = { to_stage_id: null, lost_reason_id: null, lost_reason_text: '' }
      errors.value = {}
    }
  },
)

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
    if (status === 409) {
      toast.add({
        severity: 'error',
        summary: t('documents.card.wonGate.summary'),
        detail: t('documents.card.wonGate.detail'),
        life: 7000,
      })
      return
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
.move-deal-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.move-deal-dialog__section-label {
  display: block;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
  margin-bottom: $space-2;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Current stage row
.move-deal-dialog__current {
  display: flex;
  flex-direction: column;
}

.move-deal-dialog__current-stage {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.move-deal-dialog__stage-dot {
  width: 10px;
  height: 10px;
  border-radius: $radius-circle;
  flex-shrink: 0;
}

.move-deal-dialog__stage-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

// New stage: visual list
.move-deal-dialog__new {
  display: flex;
  flex-direction: column;
}

.move-deal-dialog__stage-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 280px;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.move-deal-dialog__stage-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-sm;
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  text-align: left;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  &--selected {
    background: var(--p-primary-100); // spec §8.1 navy-tint
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-950);
      color: var(--p-primary-300);
    }
  }
}

.move-deal-dialog__stage-item-name {
  flex: 1;
}

.move-deal-dialog__stage-check {
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  flex-shrink: 0;
}

// Lost reason section
.move-deal-dialog__won-hint {
  margin: 0;
}

.move-deal-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.move-deal-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.w-full {
  width: 100%;
}
</style>
