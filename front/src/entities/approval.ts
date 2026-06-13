/**
 * Approval entities — S2.10 Documents module.
 * My approvals (pending + history).
 */

import type { ApprovalDecision } from './document'
import type { DocumentKind } from './document'

export interface MyApprovalItemDto {
  id: number
  document_id: number
  document_number: string | null
  document_kind: DocumentKind
  company_id: number | null
  company_name: string | null
  stage_id: number
  stage_name: string
  stage_order: number
  attempt: number
  status: 'pending' | 'decided'
  decision: ApprovalDecision | null
  comment: string | null
  created_at: string
  decided_at: string | null
}

export interface MyApprovalsPaginatedResponse {
  data: MyApprovalItemDto[]
  meta: {
    total: number
    per_page: number
    current_page: number
    last_page: number
  }
}

export interface MyApprovalsListParams {
  status?: 'pending' | 'decided' | null
  per_page?: number
  page?: number
}
