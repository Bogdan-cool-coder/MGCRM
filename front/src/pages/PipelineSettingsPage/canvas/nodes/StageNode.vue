<template>
  <div class="stage-node">
    <!-- Colour accent bar at top -->
    <div
      class="stage-node__bar"
      :style="{ background: accentColor }"
    />

    <!-- Header row -->
    <div class="stage-node__header">
      <span class="stage-node__name" :title="data.stage.name">{{ data.stage.name }}</span>
      <Button
        icon="pi pi-times"
        text
        rounded
        size="small"
        v-tooltip.top="t('automation.canvas.noEditStageHere')"
        disabled
        class="stage-node__delete-btn"
      />
    </div>

    <!-- Stats row -->
    <div class="stage-node__stats">
      <span class="stage-node__stat">
        {{ t('automation.canvas.stageAutos', { n: data.automationCount }) }}
      </span>
    </div>

    <!-- Add automation button -->
    <Button
      :label="t('automation.canvas.addAutomation')"
      icon="pi pi-plus"
      text
      size="small"
      class="stage-node__add-btn"
      @click.stop="data.onAddAutomation(data.stage.id)"
    />

    <!-- Source handle (right) -->
    <Handle type="source" :position="Position.Right" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import type { StageNodeData } from '../composables/usePipelineCanvas'

// ─── Props ────────────────────────────────────────────────────────────────────

interface Props {
  data: StageNodeData
}

const props = defineProps<Props>()

const { t } = useI18n()

// ─── Accent color ─────────────────────────────────────────────────────────────

const accentColor = computed<string>(() => {
  if (props.data.stage.is_won) return 'var(--p-green-500)'
  return props.data.stage.color ?? 'var(--p-primary-color)'
})
</script>

<style lang="scss" scoped>
.stage-node {
  position: relative;
  width: 260px;
  min-height: 120px;
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  flex-direction: column;

  &__bar {
    height: 4px;
    width: 100%;
    flex-shrink: 0;
  }

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: $space-2 $space-3 $space-1;
    gap: $space-2;
  }

  &__name {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--p-text-color);
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__delete-btn {
    flex-shrink: 0;
    opacity: 0.3;
    cursor: not-allowed;
  }

  &__stats {
    display: flex;
    gap: $space-3;
    padding: 0 $space-3 $space-1;
  }

  &__stat {
    font-size: 0.75rem;
    color: var(--p-text-muted-color);
  }

  &__add-btn {
    margin: 0 $space-2 $space-2;
    align-self: flex-start;
  }
}
</style>
