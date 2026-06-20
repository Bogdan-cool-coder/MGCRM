<template>
  <div v-if="visibleChips.length > 0" class="key-actions-bar">
    <!-- Chips row -->
    <div class="key-actions-bar__chips">
      <button
        v-for="chip in visibleChips"
        :key="chip.type"
        v-tooltip.bottom="chip.tooltip"
        type="button"
        class="key-actions-bar__chip"
        :class="`key-actions-bar__chip--${chip.type}`"
        @click="onChipClick(chip)"
      >
        <i :class="['pi', chip.icon]" class="key-actions-bar__chip-icon" />
        <span class="key-actions-bar__chip-label">{{ chip.label }}</span>
      </button>
    </div>

    <!-- Mark buttons (kp / contract) if not yet stamped -->
    <div v-if="showMarkKp || showMarkContract" class="key-actions-bar__mark-btns">
      <Button
        v-if="showMarkKp"
        v-tooltip.bottom="t('sales.deal.keyActions.markKpTooltip')"
        icon="pi pi-file-check"
        severity="secondary"
        text
        size="small"
        class="key-actions-bar__mark-btn"
        :loading="markingKp"
        @click="onMarkKp"
      />
      <Button
        v-if="showMarkContract"
        v-tooltip.bottom="t('sales.deal.keyActions.markContractTooltip')"
        icon="pi pi-file-edit"
        severity="secondary"
        text
        size="small"
        class="key-actions-bar__mark-btn"
        :loading="markingContract"
        @click="onMarkContract"
      />
    </div>

  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, DealKeyAction, KeyActionType } from '@/entities/sales'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  dealId: number
  keyActions: DealKeyAction[]
}>()

const emit = defineEmits<{
  dealUpdated: [keyActions: DealKeyAction[]]
  scrollToType: [type: KeyActionType]
}>()

// ─── i18n / toast ─────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

// ─── Chip config ──────────────────────────────────────────────────────────────

interface ChipConfig {
  type: KeyActionType
  icon: string
  label: string
  tooltip: string
}

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

const visibleChips = computed((): ChipConfig[] => {
  const chips: ChipConfig[] = []

  for (const action of props.keyActions) {
    // max_stage shows when ref is non-null
    if (action.type === 'max_stage') {
      if (action.ref) {
        chips.push({
          type: action.type,
          icon: 'pi-chart-line',
          label: action.ref.name,
          tooltip: t('sales.deal.keyActions.maxStageTooltip', { stage: action.ref.name }),
        })
      }
      continue
    }
    // All others show when date is non-null
    if (!action.date) continue

    const dateStr = formatDate(action.date)
    switch (action.type) {
      case 'last_presentation':
        chips.push({
          type: action.type,
          icon: 'pi-desktop',
          label: dateStr,
          tooltip: t('sales.deal.keyActions.lastPresentationTooltip', { date: dateStr }),
        })
        break
      case 'kp_sent':
        chips.push({
          type: action.type,
          icon: 'pi-file-check',
          label: dateStr,
          tooltip: t('sales.deal.keyActions.kpSentTooltip', { date: dateStr }),
        })
        break
      case 'contract_sent':
        chips.push({
          type: action.type,
          icon: 'pi-file-edit',
          label: dateStr,
          tooltip: t('sales.deal.keyActions.contractSentTooltip', { date: dateStr }),
        })
        break
      case 'last_touch':
        chips.push({
          type: action.type,
          icon: 'pi-phone',
          label: dateStr,
          tooltip: t('sales.deal.keyActions.lastTouchTooltip', { date: dateStr }),
        })
        break
      case 'last_event':
        chips.push({
          type: action.type,
          icon: 'pi-calendar',
          label: dateStr,
          tooltip: t('sales.deal.keyActions.lastEventTooltip', { date: dateStr }),
        })
        break
    }
  }

  return chips
})

// Show mark-KP button when kp_sent action has no date yet
const showMarkKp = computed((): boolean => {
  const action = props.keyActions.find((a) => a.type === 'kp_sent')
  return !!action && action.date === null
})

const showMarkContract = computed((): boolean => {
  const action = props.keyActions.find((a) => a.type === 'contract_sent')
  return !!action && action.date === null
})

// ─── Chip click → scroll to feed ──────────────────────────────────────────────

function onChipClick(chip: ChipConfig) {
  emit('scrollToType', chip.type)
}

// ─── Mark KP sent ─────────────────────────────────────────────────────────────

const markKpMutation = useMutation<DealDto>()
const markingKp = computed(() => markKpMutation.isPending.value)

async function onMarkKp() {
  try {
    const updated = await markKpMutation.run(() => salesApi.markKpSent(props.dealId))
    if (updated.key_actions) {
      emit('dealUpdated', updated.key_actions)
    }
    toast.add({
      severity: 'success',
      summary: t('sales.deal.keyActions.kpMarked'),
      life: 2500,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ─── Mark contract sent ────────────────────────────────────────────────────────

const markContractMutation = useMutation<DealDto>()
const markingContract = computed(() => markContractMutation.isPending.value)

async function onMarkContract() {
  try {
    const updated = await markContractMutation.run(() => salesApi.markContractSent(props.dealId))
    if (updated.key_actions) {
      emit('dealUpdated', updated.key_actions)
    }
    toast.add({
      severity: 'success',
      summary: t('sales.deal.keyActions.contractMarked'),
      life: 2500,
    })
  } catch (err) {
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
.key-actions-bar {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  padding: $space-2 $space-3;
  background: rgba(255, 255, 255, 0.06);
  border-radius: $radius-sm;
}

.key-actions-bar__chips {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex: 1;
  flex-wrap: wrap;
}

.key-actions-bar__chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: $radius-sm;
  background: rgba(255, 255, 255, 0.10);
  border: 1px solid rgba(255, 255, 255, 0.15);
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
  color: rgba(255, 255, 255, 0.85);
  text-decoration: none;
  white-space: nowrap;

  &:hover {
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.28);
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.5);
    outline-offset: 2px;
  }
}

.key-actions-bar__chip-icon {
  font-size: 10px;
  flex-shrink: 0;
}

.key-actions-bar__chip-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  line-height: 1;
}

// Per-type accent colors (subtle tints)
.key-actions-bar__chip--last_presentation {
  border-color: rgba(139, 92, 246, 0.35);
}

.key-actions-bar__chip--max_stage {
  border-color: rgba(59, 130, 246, 0.35);
}

.key-actions-bar__chip--kp_sent {
  border-color: rgba(16, 185, 129, 0.35);
}

.key-actions-bar__chip--contract_sent {
  border-color: rgba(245, 158, 11, 0.35);
}

.key-actions-bar__chip--last_touch {
  border-color: rgba(236, 72, 153, 0.35);
}

.key-actions-bar__chip--last_event {
  border-color: rgba(14, 165, 233, 0.35);
}

// Mark buttons (compact icon-only)
.key-actions-bar__mark-btns {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
}

.key-actions-bar__mark-btn {
  color: rgba(255, 255, 255, 0.6) !important;

  &:hover {
    color: rgba(255, 255, 255, 0.9) !important;
  }
}
</style>
