<template>
  <div class="mkb-savebar">
    <!-- Live preview -->
    <div class="mkb-savebar__preview">
      <div class="mkb-savebar__line">
        <span class="mkb-savebar__k">{{ t('motivation.builder.save_bar_plan') }}:</span>
        <span class="mkb-savebar__v">{{ formatMkMoney(totalKopecks, baseCurrency) }}</span>
      </div>
      <div class="mkb-savebar__line">
        <span class="mkb-savebar__k">{{ t('motivation.builder.save_bar_fact') }}:</span>
        <span class="mkb-savebar__fact">—</span>
        <span class="mkb-savebar__basis">
          <i
            v-tooltip.top="t('motivation.card.fact_basis_won_deals')"
            class="pi pi-info-circle"
            aria-hidden="true"
          />
          {{ t('motivation.card.fact_basis_won_deals') }}
        </span>
      </div>
    </div>

    <!-- Actions -->
    <div class="mkb-savebar__actions">
      <!-- Status transitions (permission-gated) -->
      <template v-if="canTransition">
        <Button
          v-if="status === 'draft' && cardExists"
          severity="secondary"
          outlined
          icon="pi pi-lock"
          :label="t('motivation.builder.finalize')"
          :loading="transitioning"
          @click="emit('finalize')"
        />
        <Button
          v-if="status === 'finalized'"
          severity="secondary"
          outlined
          icon="pi pi-check-circle"
          :label="t('motivation.builder.mark_paid')"
          :loading="transitioning"
          @click="emit('mark-paid')"
        />
        <Tag
          v-if="status === 'paid'"
          severity="success"
          :value="t('motivation.card.status_paid')"
        />
      </template>

      <Button
        severity="secondary"
        icon="pi pi-copy"
        :label="t('motivation.builder.copy_prev_month')"
        :loading="copyingPrev"
        :disabled="readOnly"
        @click="emit('copy-prev')"
      />
      <Button
        icon="pi pi-save"
        :label="cardExists ? t('motivation.builder.update') : t('motivation.builder.save')"
        :loading="saving"
        :disabled="readOnly"
        @click="emit('save')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { formatMkMoney } from '@/utils/motivation'
import type { MotivationStatus } from '@/entities/motivation'

defineProps<{
  totalKopecks: number
  baseCurrency: string
  status: MotivationStatus
  cardExists: boolean
  readOnly: boolean
  canTransition: boolean
  saving: boolean
  copyingPrev: boolean
  transitioning: boolean
}>()

const emit = defineEmits<{
  save: []
  'copy-prev': []
  finalize: []
  'mark-paid': []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.mkb-savebar {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  flex-wrap: wrap;
  padding: $space-3 $space-5;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-md;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.mkb-savebar__preview {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.mkb-savebar__line {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.mkb-savebar__k {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mkb-savebar__v {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mkb-savebar__fact {
  font-size: $font-size-base;
  color: $surface-500;
}

.mkb-savebar__basis {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-2xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mkb-savebar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}
</style>
