<template>
  <!-- Desktop-only guard: show message on narrow viewports -->
  <div v-if="isNarrow" class="pipeline-canvas__narrow-msg">
    <Message severity="info" :closable="false" class="pipeline-canvas__narrow-message">
      {{ t('automation.canvas.desktopOnly') }}
    </Message>
    <Button
      :label="t('automation.canvas.backToForm')"
      icon="pi pi-arrow-left"
      severity="secondary"
      size="small"
      @click="emit('switch-to-form')"
    />
  </div>

  <!-- Full canvas (desktop) -->
  <div v-else class="pipeline-canvas">
    <!-- ── Loading skeleton ─────────────────────────────────────────────── -->
    <div v-if="loading" class="pipeline-canvas__loading">
      <Skeleton width="100%" height="100%" />
      <div class="pipeline-canvas__loading-spinner">
        <ProgressSpinner style="width: 40px; height: 40px" />
      </div>
    </div>

    <!-- ── Error state ──────────────────────────────────────────────────── -->
    <div v-else-if="error" class="pipeline-canvas__error">
      <Message severity="error" :closable="false">{{ error }}</Message>
      <Button
        :label="t('common.retry')"
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        class="mt-3"
        @click="reload"
      />
    </div>

    <!-- ── Empty: no stages ─────────────────────────────────────────────── -->
    <div v-else-if="!loading && topStages.length === 0" class="pipeline-canvas__empty">
      <VueFlow
        :nodes="canvasNodes"
        :edges="[]"
        :delete-key-code="null"
        fit-view-on-init
        class="pipeline-canvas__flow"
      >
        <Background
          :variant="BackgroundVariant.Dots"
          :gap="20"
          :size="1.5"
          pattern-color="var(--p-surface-border)"
        />
      </VueFlow>
      <!-- Empty overlay -->
      <div class="pipeline-canvas__empty-overlay">
        <i class="pi pi-sitemap pipeline-canvas__empty-icon" />
        <p class="pipeline-canvas__empty-text">{{ t('automation.canvas.emptyNoStages') }}</p>
        <Button
          :label="t('automation.canvas.goToForm')"
          icon="pi pi-arrow-left"
          severity="secondary"
          size="small"
          @click="emit('switch-to-form')"
        />
      </div>
    </div>

    <!-- ── Main canvas ──────────────────────────────────────────────────── -->
    <template v-else>
      <!-- Toolbar -->
      <div class="pipeline-canvas__toolbar">
        <Button
          :label="t('automation.canvas.autoLayout')"
          icon="pi pi-sitemap"
          severity="secondary"
          size="small"
          v-tooltip.bottom="t('automation.canvas.autoLayoutHint')"
          @click="handleAutoLayout"
        />
        <Button
          :label="t('automation.canvas.fitView')"
          icon="pi pi-expand"
          severity="secondary"
          size="small"
          @click="fitView()"
        />
        <Button
          :label="t('automation.canvas.backToForm')"
          icon="pi pi-arrow-left"
          severity="secondary"
          size="small"
          @click="emit('switch-to-form')"
        />
        <!-- Saving indicator -->
        <div v-if="isSaving" class="pipeline-canvas__saving">
          <ProgressSpinner style="width: 16px; height: 16px" />
          <span class="pipeline-canvas__saving-label">{{ t('automation.canvas.layoutSaving') }}</span>
        </div>
      </div>

      <!-- Body: palette + flow -->
      <div class="pipeline-canvas__body">
        <!-- Tool Palette -->
        <ToolPalette @drag-start="onPaletteDragStart" @drag-end="onPaletteDragEnd" />

        <!-- VueFlow canvas -->
        <VueFlow
          :nodes="canvasNodes"
          :edges="canvasEdges"
          :node-types="nodeTypes"
          :delete-key-code="null"
          fit-view-on-init
          class="pipeline-canvas__flow"
          @node-drag-stop="onNodeDragStop"
          @dragover.prevent
          @drop="onDrop"
        >
          <Background
            :variant="BackgroundVariant.Dots"
            :gap="20"
            :size="1.5"
            pattern-color="var(--p-surface-border)"
          />
          <Controls :show-interactive="false" />
          <MiniMap
            :mask-color="isDark ? 'var(--p-surface-900)' : 'var(--p-surface-200)'"
            :node-color="'var(--p-surface-card)'"
          />
        </VueFlow>
      </div>

      <!-- Empty automations hint (stages present, no automations) -->
      <div v-if="automations.length === 0" class="pipeline-canvas__empty-autos-hint">
        <span>{{ t('automation.canvas.emptyNoAutos') }}</span>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, markRaw, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { Background, BackgroundVariant } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import ProgressSpinner from 'primevue/progressspinner'
import type { NodeDragEvent, NodeTypesObject } from '@vue-flow/core'
import type { PipelineStageDto, GraphLayout } from '@/entities/sales'
import type { AutomationDto, ActionKind } from '@/entities/automation'
import { salesApi } from '@/api/sales'
import { usePipelineCanvas } from './composables/usePipelineCanvas'
import { useGraphLayout } from './composables/useGraphLayout'
import AnchorNode from './nodes/AnchorNode.vue'
import StageNode from './nodes/StageNode.vue'
import AutomationNode from './nodes/AutomationNode.vue'
import ToolPalette from './ToolPalette.vue'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'

// ─── Props ────────────────────────────────────────────────────────────────────

interface Props {
  pipelineId: number | null
  stages: PipelineStageDto[]
  automations: AutomationDto[]
  graphLayout: GraphLayout | null
}

const props = withDefaults(defineProps<Props>(), {
  pipelineId: null,
  stages: () => [],
  automations: () => [],
  graphLayout: null,
})

// ─── Emits ────────────────────────────────────────────────────────────────────

const emit = defineEmits<{
  (e: 'switch-to-form'): void
  (e: 'add-automation', stageId: number | null): void
  (e: 'add-automation-with-action', stageId: number | null, actionKind: ActionKind): void
  (e: 'edit-automation', automation: AutomationDto): void
  (e: 'delete-automation', id: number): void
  (e: 'toggle-automation', id: number, isActive: boolean): void
}>()

// ─── i18n + toast ─────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

// ─── Vue Flow instance ────────────────────────────────────────────────────────

const { fitView, getNodes, screenToFlowCoordinate } = useVueFlow()

// ─── Node types (markRaw to prevent reactivity wrapping) ─────────────────────

const nodeTypes: NodeTypesObject = {
  anchorNode: markRaw(AnchorNode) as NodeTypesObject[string],
  stageNode: markRaw(StageNode) as NodeTypesObject[string],
  autoNode: markRaw(AutomationNode) as NodeTypesObject[string],
}

// ─── Dark mode detection ──────────────────────────────────────────────────────

const isDark = ref(document.documentElement.classList.contains('app-dark'))

function onThemeChange() {
  isDark.value = document.documentElement.classList.contains('app-dark')
}

const themeObserver = new MutationObserver(onThemeChange)

onMounted(() => {
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  })
})

onBeforeUnmount(() => {
  themeObserver.disconnect()
})

// ─── Desktop-only guard ───────────────────────────────────────────────────────

const MIN_WIDTH = 1100
const isNarrow = ref(window.innerWidth < MIN_WIDTH)

function onResize() {
  isNarrow.value = window.innerWidth < MIN_WIDTH
}

onMounted(() => {
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
})

// ─── Loading / error state ────────────────────────────────────────────────────

const loading = ref(false)
const error = ref<string | null>(null)

// ─── Mutable graph layout (merged with saved) ─────────────────────────────────

const localGraphLayout = ref<GraphLayout | null>(props.graphLayout)

watch(
  () => props.graphLayout,
  (val) => {
    localGraphLayout.value = val
  },
)

// ─── Graph layout utilities ───────────────────────────────────────────────────

const { forceLayout, serializePositions } = useGraphLayout()

// ─── Saving graph layout (debounced PATCH) ────────────────────────────────────

const isSaving = ref(false)
let saveTimer: ReturnType<typeof setTimeout> | null = null

async function saveLayout(layout: GraphLayout): Promise<void> {
  if (!props.pipelineId) return
  isSaving.value = true
  try {
    await salesApi.updatePipeline(props.pipelineId, { graph_layout: layout })
  } catch {
    toast.add({
      severity: 'warn',
      summary: t('automation.canvas.layoutSaveError'),
      life: 4000,
    })
  } finally {
    isSaving.value = false
  }
}

function scheduleSave(layout: GraphLayout): void {
  if (saveTimer !== null) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    void saveLayout(layout)
  }, 400)
}

onBeforeUnmount(() => {
  if (saveTimer !== null) clearTimeout(saveTimer)
})

// ─── Canvas data via composable ───────────────────────────────────────────────

const { nodes: canvasNodes, edges: canvasEdges, topStages } = usePipelineCanvas(
  () => props.stages,
  () => props.automations,
  () => localGraphLayout.value,
  {
    onAddAutomation: (stageId) => emit('add-automation', stageId),
    onEditAutomation: (automation) => emit('edit-automation', automation),
    onDeleteAutomation: (id) => emit('delete-automation', id),
    onToggleAutomation: (id, isActive) => emit('toggle-automation', id, isActive),
  },
)

// ─── Node drag stop — persist positions ──────────────────────────────────────

function onNodeDragStop(event: NodeDragEvent): void {
  const moved = Array.isArray(event.nodes) ? event.nodes : [event.node]
  if (!moved.length) return

  const serialized = serializePositions(
    canvasNodes.value.map((n) => ({
      id: n.id,
      position: n.position,
    })),
  )
  for (const n of moved) {
    serialized[n.id] = { x: n.position.x, y: n.position.y }
  }

  const newLayout: GraphLayout = { nodes: serialized }
  localGraphLayout.value = newLayout
  scheduleSave(newLayout)
}

// ─── Auto-layout button ───────────────────────────────────────────────────────

async function handleAutoLayout(): Promise<void> {
  const positions = forceLayout(props.stages, props.automations)
  const newLayout: GraphLayout = { nodes: positions }
  localGraphLayout.value = newLayout
  await saveLayout(newLayout)
}

// ─── Reload helper ────────────────────────────────────────────────────────────

function reload(): void {
  error.value = null
}

// ─── Init: trigger auto-layout if no saved layout ────────────────────────────

watch(
  () => props.stages,
  (newStages) => {
    if (
      newStages.length > 0 &&
      (!localGraphLayout.value || Object.keys(localGraphLayout.value.nodes).length === 0)
    ) {
      const positions = forceLayout(newStages, props.automations)
      const newLayout: GraphLayout = { nodes: positions }
      localGraphLayout.value = newLayout
      void saveLayout(newLayout)
    }
  },
  { immediate: true },
)

// ─── ToolPalette drag ─────────────────────────────────────────────────────────

// The palette emits these so the parent could react (e.g. highlight drop zones).
// For now they are wired but intentionally no-op — state flows via dataTransfer.
function onPaletteDragStart(): void {}
function onPaletteDragEnd(): void {}

// ─── Drop on canvas ───────────────────────────────────────────────────────────

function onDrop(event: DragEvent): void {
  // Only handle drags that originated from our palette
  const actionKind = (event.dataTransfer?.getData('application/vnd.canvas-tool') ?? '') as ActionKind | ''
  if (!actionKind) return

  event.preventDefault()

  // Convert screen coordinates to flow coordinates
  const position = screenToFlowCoordinate({
    x: event.clientX,
    y: event.clientY,
  })

  // Find the closest StageNode under the drop point
  const dropedOnStage = getNodes.value
    .filter((n) => n.type === 'stageNode')
    .find((n) => {
      const NODE_W = 260
      const NODE_H = 120
      return (
        position.x >= n.position.x &&
        position.x <= n.position.x + NODE_W &&
        position.y >= n.position.y &&
        position.y <= n.position.y + NODE_H
      )
    })

  // Extract stage_id from node id (pattern: "stage_{id}")
  let stageId: number | null = null
  if (dropedOnStage) {
    const match = dropedOnStage.id.match(/^stage_(\d+)$/)
    if (match?.[1]) stageId = parseInt(match[1], 10)
  }

  emit('add-automation-with-action', stageId, actionKind as ActionKind)
}
</script>

<style lang="scss" scoped>
.pipeline-canvas {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 600px;
  display: flex;
  flex-direction: column;

  // ── Toolbar ──────────────────────────────────────────────────────────────

  &__toolbar {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-3;
    border-bottom: 1px solid var(--p-surface-border);
    background: var(--p-surface-card);
    flex-shrink: 0;
    z-index: 10;
  }

  &__saving {
    display: flex;
    align-items: center;
    gap: $space-2;
    margin-left: auto;
  }

  &__saving-label {
    font-size: 0.75rem;
    color: var(--p-text-muted-color);
  }

  // ── Body (palette + flow) ─────────────────────────────────────────────────

  &__body {
    flex: 1;
    display: flex;
    min-height: 0;
  }

  // ── Flow canvas ──────────────────────────────────────────────────────────

  &__flow {
    flex: 1;
    width: 100%;
    min-height: 0;

    // Vue Flow theme bridge — use PrimeVue surface tokens
    --vf-node-bg: var(--p-surface-card);
    --vf-node-text: var(--p-text-color);
    --vf-connection-stroke: var(--p-primary-color);
    --vf-handle: var(--p-primary-color);
    --vf-edge-stroke: var(--p-surface-border);
    --vf-edge-stroke-selected: var(--p-primary-color);
    --vf-minimap-mask-bg: var(--p-surface-overlay);
    --vf-bg-color: var(--p-surface-ground);
  }

  // ── Loading ──────────────────────────────────────────────────────────────

  &__loading {
    position: relative;
    flex: 1;
    min-height: 400px;

    :deep(.p-skeleton) {
      height: 100% !important;
      border-radius: 0;
    }
  }

  &__loading-spinner {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  // ── Error ─────────────────────────────────────────────────────────────────

  &__error {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: $space-6;
  }

  // ── Empty: no stages ─────────────────────────────────────────────────────

  &__empty {
    flex: 1;
    position: relative;
    min-height: 400px;
    display: flex;
    flex-direction: column;
  }

  &__empty-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: $space-4;
    background: rgba(255, 255, 255, 0.85);
    pointer-events: none;

    .app-dark & {
      background: rgba(0, 0, 0, 0.6);
    }

    > * {
      pointer-events: auto;
    }
  }

  &__empty-icon {
    font-size: 2.5rem;
    color: var(--p-text-muted-color);
  }

  &__empty-text {
    font-size: 0.9375rem;
    color: var(--p-text-muted-color);
    text-align: center;
    max-width: 280px;
    margin: 0;
  }

  // ── Empty automations hint ────────────────────────────────────────────────

  &__empty-autos-hint {
    position: absolute;
    bottom: $space-6;
    left: 50%;
    transform: translateX(-50%);
    background: var(--p-surface-card);
    border: 1px solid var(--p-surface-border);
    border-radius: 6px;
    padding: $space-2 $space-4;
    font-size: 0.8125rem;
    color: var(--p-text-muted-color);
    pointer-events: none;
    z-index: 5;
    white-space: nowrap;
  }

  // ── Narrow viewport ───────────────────────────────────────────────────────

  &__narrow-msg {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.5rem;
  }

  &__narrow-message {
    width: 100%;
  }
}
</style>
