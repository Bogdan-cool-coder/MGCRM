<template>
  <div class="anchor-node">
    <div class="anchor-node__inner">
      <i class="pi pi-flag-fill anchor-node__icon" />
      <div class="anchor-node__content">
        <div class="anchor-node__title">{{ t('automation.canvas.anchorLabel') }}</div>
        <div class="anchor-node__count">
          {{ t('automation.canvas.anchorAutoCount', { n: data.onCreateCount }) }}
        </div>
      </div>
    </div>

    <!-- Add on_create automation -->
    <Button
      :label="t('automation.canvas.addAutomation')"
      icon="pi pi-plus"
      text
      size="small"
      class="anchor-node__add-btn"
      @click.stop="data.onAddAutomation()"
    />

    <!-- Outgoing handle (right side) -->
    <Handle type="source" :position="Position.Right" />
  </div>
</template>

<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import type { AnchorNodeData } from '../composables/usePipelineCanvas'

// ─── Props (Vue Flow node template receives `data` prop) ──────────────────────

interface Props {
  data: AnchorNodeData
}

defineProps<Props>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.anchor-node {
  position: relative;
  width: 200px;
  min-height: 72px;
  background: var(--p-surface-card);
  border: 2px solid var(--p-primary-color);
  border-radius: 8px;
  padding: $space-3 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &__inner {
    display: flex;
    align-items: center;
    gap: $space-3;
  }

  &__icon {
    font-size: 1.25rem;
    color: var(--p-primary-color);
    flex-shrink: 0;
  }

  &__content {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  &__title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--p-text-color);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__count {
    font-size: 0.75rem;
    color: var(--p-text-muted-color);
  }

  &__add-btn {
    align-self: flex-start;
    margin-left: -$space-2;
  }
}
</style>
