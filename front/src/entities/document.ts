/**
 * Document entities — S2.10 Documents module.
 * All monetary values are integers (kopecks).
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type ContractStatus =
  | 'draft'
  | 'submitted'
  | 'in_review'
  | 'needs_rework'
  | 'approved'
  | 'rejected'
  | 'signed'
  | 'uploaded'
  | 'archived'

export type DocumentKind = 'contract' | 'invoice' | 'act' | 'reconciliation' | 'termination_agreement'

export type AttachmentKind = 'signed_scan' | 'payment' | 'other'

// ─── Document (list item) ─────────────────────────────────────────────────────

export interface DocumentListItemDto {
  id: number
  number: string | null
  kind: DocumentKind
  status: ContractStatus
  product_code: string | null
  country_code: string | null
  title: string | null
  source_company_id: number | null
  author_user_id: number | null
  template_version: string | { id: number; code: string; version_number: number } | null
  docx_path: string | null
  pdf_path: string | null
  signed_at: string | null
  archived_at: string | null
  created_at: string
  updated_at: string
  context: Record<string, unknown> | null
  subtotal: number | null
  discount_pct: number | string | null
  discount_amount: number | null
  total: number | null
  currency: string | null
  attempt: number | null
  /** Expanded relation: company info (API field: source_company) */
  source_company?: { id: number; name: string } | null
  /** Expanded relation: author info (API field: full_name) */
  author?: { id: number; full_name: string } | null
}

// ─── Document (full detail) ───────────────────────────────────────────────────

// Full document has same fields as list item plus sub-resources loaded separately
export type DocumentDto = DocumentListItemDto

// ─── Document Item (line item) ────────────────────────────────────────────────

export interface DocumentItemDto {
  id: number
  document_id: number
  product_id: number
  plan_id: number | null
  /** Product name snapshot taken at creation time (BE field: name_snapshot). */
  name_snapshot: string
  currency: string | null
  qty: number
  unit_price: number
  line_total: number
  sort_order: number
}

// ─── Document Revision ────────────────────────────────────────────────────────

export interface DocumentRevisionDto {
  id: number
  document_id: number
  version_number: number
  /** Alias for version_number — both are present in the API response. */
  version: number
  attempt: number
  docx_path: string | null
  pdf_path: string | null
  note: string | null
  created_by_user_id: number | null
  created_by_name: string | null
  created_at: string
}

// ─── Document Remark ─────────────────────────────────────────────────────────

export interface DocumentRemarkDto {
  id: number
  document_id: number
  attempt: number
  stage_order: number
  author_user_id: number | null
  /** Nested author object from BE resource. */
  author: { id: number; full_name: string } | null
  /** Remark text (BE field name: text). */
  text: string
  is_resolved: boolean
  resolved_by_user_id: number | null
  resolved_by: { id: number; full_name: string } | null
  resolved_at: string | null
  created_at: string
}

// ─── Document Attachment ─────────────────────────────────────────────────────

export interface DocumentAttachmentDto {
  id: number
  document_id: number
  kind: AttachmentKind
  original_name: string
  size: number
  uploaded_by: number | null
  uploaded_by_name: string | null
  created_at: string
}

// ─── Approval Summary ────────────────────────────────────────────────────────

export type ApprovalDecision = 'approved' | 'rejected' | 'needs_rework' | 'pending'

export interface ApprovalVoteDto {
  user_id: number
  user_name: string
  decision: ApprovalDecision
  comment: string | null
  decided_at: string | null
}

export interface ApprovalStageDto {
  id: number
  order: number
  name: string
  min_required: number
  total: number
  approvals: ApprovalVoteDto[]
  is_active: boolean
  is_done: boolean
}

export interface ApprovalSummaryDto {
  id: number | null
  document_id: number
  attempt: number
  current_stage_order: number | null
  total_stages: number
  stages: ApprovalStageDto[]
  decision: ApprovalDecision | null
  comment: string | null
  is_current_user_approver: boolean
}

// ─── Payloads ────────────────────────────────────────────────────────────────

export interface CreateDocumentPayload {
  kind: DocumentKind
  source_company_id: number
  product_code: string
  country_code: string
  template_id: number
  title?: string | null
}

export interface PatchDocumentPayload {
  context?: Record<string, unknown>
  subtotal?: number
  discount_pct?: number
  discount_amount?: number
  total?: number
  currency?: string
  signed_at?: string | null
}

export interface DecideDocumentPayload {
  decision: 'approved' | 'rejected' | 'needs_rework'
  comment?: string | null
}

export interface CreateDocumentItemPayload {
  product_id: number
  qty: number
}

export interface UpdateDocumentItemPayload {
  qty?: number
}

// ─── Paginated wrapper ───────────────────────────────────────────────────────

export interface DocumentPaginatedResponse {
  data: DocumentListItemDto[]
  meta: {
    total: number
    per_page: number
    current_page: number
    last_page: number
  }
}

// ─── Filter params ────────────────────────────────────────────────────────────

export interface DocumentListParams {
  status?: ContractStatus | null
  kind?: DocumentKind | null
  product_code?: string | null
  country_code?: string | null
  author_user_id?: number | null
  search?: string | null
  archived?: boolean
  source_company_id?: number | null
  deal_id?: number | null
  per_page?: number
  page?: number
}
