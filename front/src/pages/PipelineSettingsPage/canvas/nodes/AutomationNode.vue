<template>
  <div
    class="auto-node"
    :class="{ 'auto-node--inactive': !data.automation.is_active }"
    @click.stop="data.onEdit(data.automation)"
  >
    <!-- Target handle (left) -->
    <Handle type="target" :position="Position.Left" />

    <!-- Header: icon + name -->
    <div class="auto-node__header">
      <i :class="['pi', actionIcon, 'auto-node__icon']" />
      <span class="auto-node__name" :title="data.automation.name">
        {{ data.automation.name }}
      </span>
    </div>

    <!-- Trigger summary -->
    <div class="auto-node__trigger">
      {{ triggerSummary }}
    </div>

    <div class="auto-node__divider" />

    <!-- Footer: toggle + actions -->
    <div class="auto-node__footer" @click.stop>
      <ToggleSwitch
        :model-value="data.automation.is_active"
        class="auto-node__toggle"
        @update:model-value="(v: boolean) => data.onToggle(data.automation.id, v)"
      />
      <div class="auto-node__actions">
        <Button
          icon="pi pi-pencil"
          text
          rounded
          size="small"
          :title="t('common.edit')"
          @click.stop="data.onEdit(data.automation)"
        />
        <Button
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
          :title="t('common.delete')"
          @click.stop="data.onDelete(data.automation.id)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import type { AutomationNodeData } from '../composables/usePipelineCanvas'

// ─── Props ────────────────────────────────────────────────────────────────────

interface Props {
  data: AutomationNodeData
}

const props = defineProps<Props>()

const { t } = useI18n()

// ─── Action icon map ──────────────────────────────────────────────────────────

const ACTION_ICONS: Record<string, string> = {
  tg_notify: 'pi-telegram',
  create_task: 'pi-clipboard',
  set_field: 'pi-pencil-square',
  generate_document: 'pi-file',
  change_owner: 'pi-user-edit',
  change_stage: 'pi-arrow-right-circle',
  webhook: 'pi-wifi',
  email: 'pi-envelope',
}

const actionIcon = computed<string>(() => {
  return ACTION_ICONS[props.data.automation.action_kind] ?? 'pi-bolt'
})

// ─── Trigger summary (human-readable) ────────────────────────────────────────

const triggerSummary = computed<string>(() => {
  const auto = props.data.automation
  const cfg = auto.trigger_config
  switch (auto.trigger_kind) {
    case 'on_enter_stage':
      return t('automation.canvas.triggerEdge.on_enter_stage')
    case 'on_create':
      return t('automation.canvas.triggerEdge.on_create')
    case 'idle_in_stage_days': {
      const days = typeof cfg['days'] === 'number' ? cfg['days'] : '?'
      return t('automation.canvas.triggerEdge.idle_in_stage_days', { n: days })
    }
    case 'date_field_approaching': {
      const days = typeof cfg['days'] === 'number' ? cfg['days'] : '?'
      return t('automation.canvas.triggerEdge.date_field_approaching', { n: days })
    }
    default:
      return auto.trigger_kind
  }
})
</script>

<style lang="scss" scoped>
.auto-node {
  position: relative;
  width: 220px;
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s;

  &:hover {
    border-color: var(--p-primary-color);
  }

  &--inactive {
    opacity: 0.65;
  }

  &__header {
    display: flex;
    align-items: flex-start;
    gap: $space-2;
    padding: $space-3 $space-3 $space-1;
  }

  &__icon {
    font-size: 1rem;
    color: var(--p-primary-color);
    flex-shrink: 0;
    margin-top: 2px;
  }

  &__name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--p-text-color);
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-width: 0;
  }

  &__trigger {
    font-size: 0.75rem;
    color: var(--p-text-muted-color);
    padding: 0 $space-3 $space-2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__divider {
    height: 1px;
    background: var(--p-surface-border);
    margin: 0 $space-3;
  }

  &__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: $space-2 $space-2 $space-2 $space-3;
    gap: $space-1;
  }

  &__toggle {
    flex-shrink: 0;
  }

  &__actions {
    display: flex;
    gap: 0;
    flex-shrink: 0;
  }
}
</style>
