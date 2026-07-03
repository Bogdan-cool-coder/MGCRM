<template>
  <div class="mkb-card">
    <header class="mkb-card__head">
      <i class="pi pi-sliders-h mkb-head-icon" aria-hidden="true" />
      <span class="mkb-eyebrow">{{ t('motivation.builder.step_positions') }}</span>
    </header>

    <p class="mkb-hint mkb-positions__hint">{{ t('motivation.builder.toggle_hint') }}</p>

    <div class="mkb-positions">
      <ToggleButton
        v-for="row in rows"
        :key="row.kind"
        :model-value="row.enabled"
        :disabled="disabled"
        :on-label="positionLabel(row.kind)"
        :off-label="positionLabel(row.kind)"
        :on-icon="MK_ITEM_ICONS[row.kind]"
        :off-icon="MK_ITEM_ICONS[row.kind]"
        class="mkb-positions__toggle"
        @update:model-value="(v) => (row.enabled = v)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ToggleButton from 'primevue/togglebutton'
import { MK_ITEM_ICONS, type MotivationItemKind } from '@/entities/motivation'
import type { PlanRowForm } from './useMotivationBuilder'

defineProps<{
  rows: PlanRowForm[]
  disabled: boolean
}>()

const { t } = useI18n()

const LABEL_KEYS: Record<MotivationItemKind, string> = {
  base_salary: 'motivation.card.position_base',
  commission: 'motivation.card.position_commission',
  kpi: 'motivation.card.position_kpi',
  bonus: 'motivation.card.position_bonus',
  team_kpi: 'motivation.card.position_team_bonus',
}

const positionLabel = (kind: MotivationItemKind): string => t(LABEL_KEYS[kind])
</script>

<style lang="scss" scoped>
@use '@/pages/SettingsPage/components/sections/motivation/mkb-shared' as *;

.mkb-positions__hint {
  margin: 0 0 $space-3;
}

.mkb-positions {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}
</style>
