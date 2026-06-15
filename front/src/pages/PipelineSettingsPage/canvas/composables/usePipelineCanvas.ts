/**
 * usePipelineCanvas — builds Vue Flow nodes[] and edges[] from pipeline data.
 *
 * Mapping:
 *   AnchorNode     → id="anchor"         (virtual, 1 per pipeline)
 *   StageNode      → id="stage_{id}"     (one per top-level stage, sorted by sort_order)
 *   AutomationNode → id="auto_{id}"      (one per automation)
 *
 * Edges:
 *   anchor → auto_{id}        when automation.stage_id === null (on_create)
 *   stage_{id} → auto_{id}   when automation.stage_id === stage.id
 *
 * Positions come from useGraphLayout: merged with pipeline.graph_layout.
 * Dangling keys in graph_layout (nodes no longer in data) are silently ignored.
 *
 * Node actions (add/edit/delete) are passed as callbacks in node.data so that
 * custom node components can call them directly (Vue Flow pattern for node→parent
 * communication without DOM event bubbling).
 */

import { computed } from 'vue'
import type { Node, Edge } from '@vue-flow/core'
import type { PipelineStageDto, GraphLayout } from '@/entities/sales'
import type { AutomationDto, TriggerKind } from '@/entities/automation'
import { useGraphLayout } from './useGraphLayout'

// ─── Node data payloads (typed for custom node components) ───────────────────

export interface AnchorNodeData {
  onCreateCount: number
  onAddAutomation: () => void
}

export interface StageNodeData {
  stage: PipelineStageDto
  automationCount: number
  onAddAutomation: (stageId: number) => void
}

export interface AutomationNodeData {
  automation: AutomationDto
  onEdit: (automation: AutomationDto) => void
  onDelete: (id: number) => void
  onToggle: (id: number, isActive: boolean) => void
}

// ─── Edge styles by trigger kind ─────────────────────────────────────────────

function edgeStyleForTrigger(trigger: TriggerKind): {
  style: Record<string, string>
  animated: boolean
  className?: string
} {
  switch (trigger) {
    case 'on_enter_stage':
      return {
        style: { stroke: 'var(--p-green-500)', strokeWidth: '2' },
        animated: true,
      }
    case 'idle_in_stage_days':
      return {
        style: { stroke: 'var(--p-orange-500)', strokeWidth: '2', strokeDasharray: '5,5' },
        animated: false,
        className: 'vf-edge--dashed',
      }
    case 'date_field_approaching':
      return {
        style: { stroke: 'var(--p-blue-400)', strokeWidth: '2', strokeDasharray: '5,5' },
        animated: false,
        className: 'vf-edge--dashed',
      }
    case 'on_create':
    default:
      return {
        style: { stroke: 'var(--p-primary-color)', strokeWidth: '2' },
        animated: false,
      }
  }
}

// ─── Trigger label for edge label ─────────────────────────────────────────────

function triggerEdgeLabel(trigger: TriggerKind, triggerConfig: Record<string, unknown>): string {
  switch (trigger) {
    case 'idle_in_stage_days': {
      const days = typeof triggerConfig['days'] === 'number' ? triggerConfig['days'] : '?'
      return `idle:${days}`
    }
    case 'date_field_approaching': {
      const days = typeof triggerConfig['days'] === 'number' ? triggerConfig['days'] : '?'
      return `date:${days}`
    }
    default:
      return trigger
  }
}

// ─── Composable ───────────────────────────────────────────────────────────────

export function usePipelineCanvas(
  stages: () => PipelineStageDto[],
  automations: () => AutomationDto[],
  graphLayout: () => GraphLayout | null,
  callbacks: {
    onAddAutomation: (stageId: number | null) => void
    onEditAutomation: (automation: AutomationDto) => void
    onDeleteAutomation: (id: number) => void
    onToggleAutomation: (id: number, isActive: boolean) => void
  },
) {
  const { computeLayout } = useGraphLayout()

  // ── Computed: only top-level stages sorted by sort_order ──────────────────
  const topStages = computed<PipelineStageDto[]>(() =>
    stages()
      .filter((s) => s.parent_stage_id === null)
      .sort((a, b) => a.sort_order - b.sort_order),
  )

  // ── Computed: automations grouped by stage_id ─────────────────────────────
  const autosByStage = computed<Map<number | null, AutomationDto[]>>(() => {
    const map = new Map<number | null, AutomationDto[]>()
    for (const a of automations()) {
      const key = a.stage_id ?? null
      const list = map.get(key) ?? []
      list.push(a)
      map.set(key, list)
    }
    return map
  })

  // ── Computed: position layout merged with saved graph_layout ──────────────
  const layout = computed(() => {
    const saved = graphLayout()?.nodes ?? {}
    return computeLayout(stages(), automations(), saved)
  })

  // ── Computed: Vue Flow nodes ───────────────────────────────────────────────
  const nodes = computed<Node[]>(() => {
    const result: Node[] = []
    const positions = layout.value
    const allAutos = automations()
    const stageList = topStages.value
    const onCreateAutos = autosByStage.value.get(null) ?? []

    // AnchorNode
    const anchorPos = positions['anchor'] ?? { x: 40, y: 200 }
    result.push({
      id: 'anchor',
      type: 'anchorNode',
      position: { x: anchorPos.x, y: anchorPos.y },
      data: {
        onCreateCount: onCreateAutos.length,
        onAddAutomation: () => callbacks.onAddAutomation(null),
      } satisfies AnchorNodeData,
    })

    // StageNodes
    for (const stage of stageList) {
      const nodeId = `stage_${stage.id}`
      const pos = positions[nodeId] ?? { x: 320, y: 200 }
      const stageAutos = autosByStage.value.get(stage.id) ?? []
      result.push({
        id: nodeId,
        type: 'stageNode',
        position: { x: pos.x, y: pos.y },
        data: {
          stage,
          automationCount: stageAutos.length,
          onAddAutomation: (id: number) => callbacks.onAddAutomation(id),
        } satisfies StageNodeData,
      })
    }

    // AutomationNodes
    for (const auto of allAutos) {
      const nodeId = `auto_${auto.id}`
      const pos = positions[nodeId] ?? { x: 620, y: 200 }
      result.push({
        id: nodeId,
        type: 'autoNode',
        position: { x: pos.x, y: pos.y },
        data: {
          automation: auto,
          onEdit: (a: AutomationDto) => callbacks.onEditAutomation(a),
          onDelete: (id: number) => callbacks.onDeleteAutomation(id),
          onToggle: (id: number, isActive: boolean) => callbacks.onToggleAutomation(id, isActive),
        } satisfies AutomationNodeData,
      })
    }

    return result
  })

  // ── Computed: Vue Flow edges ───────────────────────────────────────────────
  const edges = computed<Edge[]>(() => {
    const result: Edge[] = []
    const allAutos = automations()

    for (const auto of allAutos) {
      const sourceId = auto.stage_id === null ? 'anchor' : `stage_${auto.stage_id}`
      const targetId = `auto_${auto.id}`
      const edgeId = `edge_${sourceId}_${targetId}`

      const { style, animated, className } = edgeStyleForTrigger(auto.trigger_kind)
      const label = triggerEdgeLabel(auto.trigger_kind, auto.trigger_config)

      result.push({
        id: edgeId,
        source: sourceId,
        target: targetId,
        type: 'smoothstep',
        animated,
        label,
        style,
        ...(className ? { class: className } : {}),
        data: {
          trigger: auto.trigger_kind,
          triggerConfig: auto.trigger_config,
        },
      })
    }

    return result
  })

  return {
    nodes,
    edges,
    topStages,
    autosByStage,
    layout,
  }
}
