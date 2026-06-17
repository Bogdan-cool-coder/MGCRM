<template>
  <div class="tool-palette" :class="{ 'tool-palette--expanded': expanded }">
    <!-- Toggle button -->
    <button
      class="tool-palette__toggle"
      :title="expanded ? t('automation.canvas.paletteCollapse') : t('automation.canvas.paletteExpand')"
      @click="expanded = !expanded"
    >
      <i :class="['pi', expanded ? 'pi-chevron-left' : 'pi-chevron-right']" />
    </button>

    <!-- Title (only when expanded) -->
    <div v-if="expanded" class="tool-palette__title">
      {{ t('automation.canvas.paletteTitle') }}
    </div>

    <!-- Tools list -->
    <div class="tool-palette__tools">
      <div
        v-for="tool in TOOLS"
        :key="tool.actionKind"
        class="tool-palette__item"
        draggable="true"
        :title="expanded ? undefined : tool.label"
        @dragstart="onDragStart($event, tool.actionKind)"
        @dragend="onDragEnd"
      >
        <i :class="['pi', tool.icon, 'tool-palette__icon']" />
        <span v-if="expanded" class="tool-palette__label">{{ tool.label }}</span>
      </div>
    </div>

    <!-- Hint (only when expanded) -->
    <div v-if="expanded" class="tool-palette__hint">
      {{ t('automation.canvas.paletteHint') }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ActionKind } from '@/entities/automation'

// ─── i18n ─────────────────────────────────────────────────────────────────────

const { t } = useI18n()

// ─── Emits ────────────────────────────────────────────────────────────────────

const emit = defineEmits<{
  (e: 'drag-start', actionKind: ActionKind): void
  (e: 'drag-end'): void
}>()

// ─── State ────────────────────────────────────────────────────────────────────

const expanded = ref(true)

// ─── Tool definitions ─────────────────────────────────────────────────────────

interface ToolDef {
  icon: string
  label: string
  actionKind: ActionKind
}

const TOOLS: ToolDef[] = [
  { icon: 'pi-telegram',           label: t('automation.canvas.toolTelegram'), actionKind: 'tg_notify' },
  { icon: 'pi-clipboard',          label: t('automation.canvas.toolTask'),      actionKind: 'create_task' },
  { icon: 'pi-pencil-square',      label: t('automation.canvas.toolField'),     actionKind: 'set_field' },
  { icon: 'pi-file',               label: t('automation.canvas.toolDocument'),  actionKind: 'generate_document' },
  { icon: 'pi-user-edit',          label: t('automation.canvas.toolOwner'),     actionKind: 'change_owner' },
  { icon: 'pi-arrow-right-circle', label: t('automation.canvas.toolStage'),     actionKind: 'change_stage' },
  { icon: 'pi-wifi',               label: t('automation.canvas.toolWebhook'),   actionKind: 'webhook' },
  { icon: 'pi-envelope',           label: t('automation.canvas.toolEmail'),     actionKind: 'email' },
]

// ─── Drag ─────────────────────────────────────────────────────────────────────

function onDragStart(event: DragEvent, actionKind: ActionKind): void {
  if (event.dataTransfer) {
    event.dataTransfer.setData('application/vnd.canvas-tool', actionKind)
    event.dataTransfer.effectAllowed = 'copy'
  }
  emit('drag-start', actionKind)
}

function onDragEnd(): void {
  emit('drag-end')
}
</script>

<style lang="scss" scoped>
.tool-palette {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 56px;
  background: var(--p-surface-card);
  border-right: 1px solid var(--p-surface-border);
  transition: width 0.2s ease;
  flex-shrink: 0;
  z-index: 10;
  overflow: hidden;

  &--expanded {
    width: 180px;
  }

  // ── Toggle button ─────────────────────────────────────────────────────────

  &__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 36px;
    border: none;
    border-bottom: 1px solid var(--p-surface-border);
    background: transparent;
    color: var(--p-text-muted-color);
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.15s, color 0.15s;

    &:hover {
      background: var(--p-surface-hover);
      color: var(--p-text-color);
    }

    i {
      font-size: 0.75rem;
    }
  }

  // ── Title ─────────────────────────────────────────────────────────────────

  &__title {
    padding: $space-2 $space-3 $space-1;
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--p-text-muted-color);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    white-space: nowrap;
    overflow: hidden;
  }

  // ── Tools list ────────────────────────────────────────────────────────────

  &__tools {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: $space-1 0;
  }

  // ── Single tool item ──────────────────────────────────────────────────────

  &__item {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-3;
    cursor: grab;
    color: var(--p-text-color);
    transition: background 0.15s, color 0.15s;
    user-select: none;
    white-space: nowrap;

    &:hover {
      background: var(--p-surface-hover);
      color: var(--p-primary-color);
    }

    &:active {
      cursor: grabbing;
    }
  }

  &__icon {
    font-size: 1rem;
    flex-shrink: 0;
    width: 18px;
    text-align: center;
  }

  &__label {
    font-size: 0.8125rem;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  // ── Hint ──────────────────────────────────────────────────────────────────

  &__hint {
    padding: $space-2 $space-3;
    font-size: 0.6875rem;
    color: var(--p-text-muted-color);
    border-top: 1px solid var(--p-surface-border);
    white-space: nowrap;
    overflow: hidden;
  }
}
</style>
