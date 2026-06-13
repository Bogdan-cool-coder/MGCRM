/**
 * TemplateVariable entities — S2.10 Documents module.
 * Catalog of template placeholders ({{key}}).
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type TemplateVariableType = 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox'

// ─── Entities ────────────────────────────────────────────────────────────────

export interface TemplateVariableOptionDto {
  value: string
  name: string
}

export interface TemplateVariableDto {
  id: number
  key: string
  label: string
  help_text: string | null
  var_type: TemplateVariableType
  options: TemplateVariableOptionDto[]
  default_value: string | null
  required: boolean
  group: string | null
  product_codes: string[]
  country_codes: string[]
  sort_order: number
  is_active: boolean
}

// ─── Payloads ────────────────────────────────────────────────────────────────

export interface TemplateVariableListParams {
  var_type?: TemplateVariableType | null
  product_codes?: string[]
  country_codes?: string[]
  group?: string | null
  is_active?: boolean
  search?: string | null
}

export interface CreateTemplateVariablePayload {
  key: string
  label: string
  help_text?: string | null
  var_type: TemplateVariableType
  options?: TemplateVariableOptionDto[]
  default_value?: string | null
  required?: boolean
  group?: string | null
  product_codes?: string[]
  country_codes?: string[]
  sort_order?: number
  is_active?: boolean
}

export type PatchTemplateVariablePayload = Partial<Omit<CreateTemplateVariablePayload, 'key'>>
