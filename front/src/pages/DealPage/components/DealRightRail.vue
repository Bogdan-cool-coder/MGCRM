<template>
  <div class="deal-rail">
    <!-- Stage -->
    <div class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.stage') }}</span>
      <DealStageTag :stage="deal.stage" />
    </div>

    <!-- Pipeline -->
    <div class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.pipeline') }}</span>
      <span class="deal-rail__value">{{ deal.pipeline.name }}</span>
    </div>

    <!-- Owner -->
    <div class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.owner') }}</span>
      <div class="deal-rail__owner">
        <div class="deal-rail__avatar">
          <img
            v-if="deal.owner.avatar_path"
            :src="deal.owner.avatar_path"
            :alt="deal.owner.name"
          />
          <i v-else class="pi pi-user" />
        </div>
        <span class="deal-rail__value">{{ deal.owner.name }}</span>
      </div>
    </div>

    <!-- Department -->
    <div v-if="deal.department_name" class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.department') }}</span>
      <span class="deal-rail__value">{{ deal.department_name }}</span>
    </div>

    <!-- Created -->
    <div class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.createdAt') }}</span>
      <span class="deal-rail__value">{{ formatDate(deal.created_at) }}</span>
    </div>

    <!-- Updated -->
    <div class="deal-rail__section">
      <span class="deal-rail__label">{{ t('sales.deal.page.rail.updatedAt') }}</span>
      <span class="deal-rail__value">{{ formatDate(deal.updated_at) }}</span>
    </div>

    <!-- Won-gate warning -->
    <Message
      v-if="deal.stage.won_gate"
      severity="warn"
      :closable="false"
      size="small"
      class="deal-rail__won-gate"
    >
      <i class="pi pi-exclamation-triangle" />
      {{ t('sales.deal.page.rail.wonGateWarning') }}
    </Message>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import DealStageTag from './DealStageTag.vue'
import type { DealDto } from '@/entities/sales'

defineProps<{
  deal: DealDto
}>()

const { t } = useI18n()

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<style lang="scss" scoped>
.deal-rail {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  padding: $space-4;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deal-rail__section {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-rail__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-400;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.deal-rail__value {
  font-size: $font-size-sm;
  color: $surface-800;
  font-weight: $font-weight-medium;
}

.deal-rail__owner {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-rail__avatar {
  width: 28px;
  height: 28px;
  border-radius: $radius-xl;
  background: $surface-200;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  i {
    font-size: $font-size-xs;
    color: $surface-500;
  }
}

.deal-rail__won-gate {
  margin: 0;
}
</style>
