/**
 * useGraphLayout — auto-layout algorithm for the pipeline canvas.
 *
 * Strategy (no dagre dependency):
 *   - AnchorNode:       x=40,  y=centerY
 *   - StageNodes:       x=320, spaced vertically by sort_order, step=160px
 *   - AutomationNodes:  x=620, grouped under their stage (or anchor), step=110px
 *
 * Merges with existing `graph_layout.nodes` — if a node already has a position
 * it is preserved; only new nodes without a position get auto-placed.
 */

import type { PipelineStageDto } from '@/entities/sales'
import type { AutomationDto } from '@/entities/automation'
import type { GraphLayoutNodes } from '@/entities/sales'

// ─── Constants ────────────────────────────────────────────────────────────────

const ANCHOR_X = 40
const STAGE_X = 320
const AUTO_X = 620

const STAGE_STEP_Y = 160
const AUTO_STEP_Y = 110

// ─── Types ────────────────────────────────────────────────────────────────────

export interface NodePosition {
  x: number
  y: number
}

export interface ComputedLayout {
  [nodeId: string]: NodePosition
}

// ─── Composable ───────────────────────────────────────────────────────────────

export function useGraphLayout() {
  /**
   * Compute a full layout for all nodes.
   * Merges with `savedNodes` — saved positions take priority.
   * Returns the complete position map for all nodes.
   */
  function computeLayout(
    stages: PipelineStageDto[],
    automations: AutomationDto[],
    savedNodes: GraphLayoutNodes = {},
  ): ComputedLayout {
    // Only top-level stages (parent_stage_id === null), sorted by sort_order
    const topStages = stages
      .filter((s) => s.parent_stage_id === null)
      .sort((a, b) => a.sort_order - b.sort_order)

    const result: ComputedLayout = {}

    // ── Group automations ─────────────────────────────────────────────────────
    const autosByStage = new Map<number | null, AutomationDto[]>()
    for (const a of automations) {
      const key = a.stage_id ?? null
      const list = autosByStage.get(key) ?? []
      list.push(a)
      autosByStage.set(key, list)
    }

    // ── Calculate total height for vertical centering ─────────────────────────
    const stageCount = topStages.length
    const totalStageHeight = stageCount > 0 ? (stageCount - 1) * STAGE_STEP_Y : 0

    // on_create automations (anchor's children)
    const anchorAutos = autosByStage.get(null) ?? []
    const totalAnchorAutoHeight = anchorAutos.length > 0 ? (anchorAutos.length - 1) * AUTO_STEP_Y : 0

    // Center Y for the whole graph
    const maxBlockHeight = Math.max(totalStageHeight, totalAnchorAutoHeight)
    const centerY = Math.max(200, Math.round(maxBlockHeight / 2) + 100)

    // ── AnchorNode ────────────────────────────────────────────────────────────
    const anchorId = 'anchor'
    result[anchorId] = savedNodes[anchorId] ?? { x: ANCHOR_X, y: centerY }

    // ── on_create automations (under anchor) ──────────────────────────────────
    const anchorAutoStartY = centerY - Math.round(((anchorAutos.length - 1) * AUTO_STEP_Y) / 2)
    anchorAutos.forEach((auto, idx) => {
      const id = `auto_${auto.id}`
      result[id] = savedNodes[id] ?? {
        x: AUTO_X,
        y: anchorAutoStartY + idx * AUTO_STEP_Y,
      }
    })

    // ── StageNodes + their automations ────────────────────────────────────────
    const stageStartY = centerY - Math.round(totalStageHeight / 2)

    topStages.forEach((stage, stageIdx) => {
      const stageId = `stage_${stage.id}`
      const stageY = stageStartY + stageIdx * STAGE_STEP_Y
      result[stageId] = savedNodes[stageId] ?? { x: STAGE_X, y: stageY }

      const stageAutos = autosByStage.get(stage.id) ?? []
      const autoGroupHeight = (stageAutos.length - 1) * AUTO_STEP_Y
      const autoStartY = stageY - Math.round(autoGroupHeight / 2)

      stageAutos.forEach((auto, autoIdx) => {
        const autoId = `auto_${auto.id}`
        result[autoId] = savedNodes[autoId] ?? {
          x: AUTO_X,
          y: autoStartY + autoIdx * AUTO_STEP_Y,
        }
      })
    })

    return result
  }

  /**
   * Apply fresh auto-layout ignoring any saved positions.
   * Used when "Auto-layout" button is clicked.
   */
  function forceLayout(
    stages: PipelineStageDto[],
    automations: AutomationDto[],
  ): ComputedLayout {
    return computeLayout(stages, automations, {})
  }

  /**
   * Serialize current Vue Flow node positions into GraphLayoutNodes.
   * Pass in: array of { id, position: { x, y } }
   */
  function serializePositions(
    nodes: Array<{ id: string; position: { x: number; y: number } }>,
  ): GraphLayoutNodes {
    const result: GraphLayoutNodes = {}
    for (const node of nodes) {
      result[node.id] = { x: node.position.x, y: node.position.y }
    }
    return result
  }

  return {
    computeLayout,
    forceLayout,
    serializePositions,
  }
}
