/**
 * Template entities — S2.10 Documents module.
 * docx template + version + AI check.
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type AiCheckStatus = 'pending' | 'checking' | 'checked' | 'failed'

/** Template storage kind — matches templates.kind column in the DB. */
export type TemplateKind = 'docx' | 'yaml' | 'text'

// ─── Template Version ─────────────────────────────────────────────────────────

export interface AiRemarkDto {
  type: 'error' | 'warning'
  severity: 'high' | 'medium' | 'low'
  text: string
}

export interface TemplateVersionDto {
  id: number
  template_id: number
  version_number: number
  docx_path: string | null
  pdf_ok: boolean | null
  ai_check_status: AiCheckStatus
  ai_remarks: AiRemarkDto[]
  ai_overridden: boolean
  created_by_user_id: number | null
  created_by_name: string | null
  created_at: string
}

// ─── Template ─────────────────────────────────────────────────────────────────

export interface TemplateDto {
  id: number
  code: string
  title: string
  kind: TemplateKind
  category: string | null
  product_codes: string[]
  country_codes: string[]
  client_category_codes: string[]
  current_version: TemplateVersionDto | null
  created_at: string
  updated_at: string
}

export interface TemplateListItemDto {
  id: number
  code: string
  title: string
  kind: TemplateKind
  product_codes: string[]
  country_codes: string[]
  current_version: TemplateVersionDto | null
  created_at: string
}

// ─── Payloads ────────────────────────────────────────────────────────────────

export interface TemplateListParams {
  kind?: TemplateKind | null
  search?: string | null
  product_code?: string | null
  country_code?: string | null
}

export interface PatchTemplatePayload {
  title?: string
  product_codes?: string[]
  country_codes?: string[]
  client_category_codes?: string[]
}

export interface CreateTemplatePayload {
  code: string
  kind: TemplateKind
  title: string
  category?: string | null
  product_codes?: string[]
  country_codes?: string[]
  client_category_codes?: string[]
  department_ids?: number[]
}
