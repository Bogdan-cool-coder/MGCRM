/**
 * ApprovalRoute entities — S2.10 Documents module.
 * Approval workflow routes and stages.
 */

import type { DocumentKind } from './document'

// ─── Entities ────────────────────────────────────────────────────────────────

export interface ApprovalRouteStageDto {
  id: number
  route_id: number
  order: number
  name: string
  user_ids: number[]
  users?: { id: number; full_name: string }[]
  min_required: number
}

export interface ApprovalRouteDto {
  id: number
  title: string
  document_kind: DocumentKind
  template_id: number | null
  template_code: string | null
  is_default: boolean
  is_active: boolean
  stages: ApprovalRouteStageDto[]
  created_at: string
  updated_at: string
}

export interface ApprovalRouteListItemDto {
  id: number
  title: string
  document_kind: DocumentKind
  template_id: number | null
  template_code: string | null
  is_default: boolean
  is_active: boolean
  stages_count: number
  created_at: string
}

// ─── Payloads ────────────────────────────────────────────────────────────────

export interface ApprovalRouteStagePayload {
  order: number
  name: string
  user_ids: number[]
  min_required: number
}

export interface CreateApprovalRoutePayload {
  title: string
  document_kind: DocumentKind
  template_id?: number | null
  is_default?: boolean
  stages: ApprovalRouteStagePayload[]
}

export type PatchApprovalRoutePayload = Partial<CreateApprovalRoutePayload>
