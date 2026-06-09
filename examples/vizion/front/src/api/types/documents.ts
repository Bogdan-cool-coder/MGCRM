import type { LocalizedText } from '@/shared/types'

/**
 * Snake_case mirrors of the backend Documents responses (DOCUMENTS.md
 * §`document_templates` / `generated_documents`). These are the raw shapes the
 * `documents.ts` API client returns; the `entities/document` mappers translate
 * them into the camelCase domain types consumed by pages / components.
 */

/** Two template flavours — HTML commercial proposal vs uploaded Word template. */
export type DocumentTemplateType = 'html' | 'docx'

/**
 * Author projection returned with custom (non-system) templates. `null` for
 * system templates — mirrors `ReportAuthorDto` / `DashboardAuthorDto`.
 */
export interface DocumentAuthorDto {
  id: number
  name: string
  email: string
}

/**
 * docx placeholder → field-catalog key map (`config.field_mapping`). Keys are
 * the bare `${...}` token names from the uploaded source; values are
 * field-catalog `key`s (e.g. `estate_price`). Saved via `update()`.
 */
export type DocumentFieldMappingDto = Record<string, string>

/**
 * Template config blob (`document_templates.config`, jsonb). The concrete shape
 * is template-type-specific (HTML render settings vs docx placeholder
 * mappings); the open index signature keeps it a pass-through while the docx
 * `field_mapping` key is read/written directly by the M6 mapping UI.
 */
export interface DocumentTemplateConfigDto {
  /** docx-only: placeholder → field-catalog key map. */
  field_mapping?: DocumentFieldMappingDto
  [key: string]: unknown
}

/**
 * List item from `GET /api/documents`. Tight projection (no `config`) for the
 * library grid.
 */
export interface DocumentTemplateListItemDto {
  id: number
  name: LocalizedText
  description: LocalizedText | null
  type: DocumentTemplateType
  is_system: boolean
  is_published: boolean
  /** `null` for system templates. */
  user_id: number | null
  author: DocumentAuthorDto | null
}

/**
 * Full template detail from `GET /api/documents/{id}` (and returned by the
 * publish / unpublish / create / update endpoints).
 */
export interface DocumentTemplateDto {
  id: number
  name: LocalizedText
  description: LocalizedText | null
  type: DocumentTemplateType
  config: DocumentTemplateConfigDto
  source_path: string | null
  is_system: boolean
  is_published: boolean
  /** `null` for system templates. */
  user_id: number | null
  author: DocumentAuthorDto | null
  chat_message_id: number | null
  created_at: string
  updated_at: string
}

/** Generation lifecycle states (`generated_documents.status`). */
export type GeneratedDocumentStatus = 'pending' | 'processing' | 'done' | 'error'

/**
 * One generation run from `GET /api/documents/generated/{id}`
 * (`DocumentController::generatedStatus`). The async `GenerateDocumentJob`
 * flips `status` pending → processing → done | error and fills `pdf_path` /
 * `docx_path` once a file is rendered.
 *
 * NB: the status endpoint returns a *tight* projection — `company_id`,
 * `user_id` and the `params` snapshot are persisted on the row but NOT echoed
 * back here (see the controller). The frontend only needs the lifecycle fields
 * for the generate → poll → download flow.
 */
export interface GeneratedDocumentDto {
  id: number
  document_template_id: number
  title: string
  status: GeneratedDocumentStatus
  pdf_path: string | null
  docx_path: string | null
  error: string | null
  created_at: string
  updated_at: string
}

/**
 * 202 response from `POST /api/documents/{id}/generate`. The backend returns
 * the new `GeneratedDocument` id under `generated_document_id` (see
 * `DocumentController::generate`).
 */
export interface GenerateDocumentResponseDto {
  generated_document_id: number
}

/** Response from `previewHtml` — server-rendered HTML for the sandboxed iframe. */
export interface DocumentPreviewResponseDto {
  html: string
}

/**
 * Params accepted by `POST /api/documents/{id}/generate`. `estate_sell_id` is
 * the MacroData object the proposal is built for; `promotion_id` + `discount`
 * drive the discount calculator (validated against the promo range backend
 * side). All optional so callers can generate with partial input.
 */
export interface DocumentGenerateParams {
  title?: string
  estate_sell_id?: number
  promotion_id?: number | null
  discount?: number | null
  [key: string]: unknown
}

/**
 * Params accepted by `POST /api/documents/{id}/preview-html`. Same selectors as
 * generate plus an explicit render `locale` (the iframe preview can differ from
 * the app locale). Synchronous — never persists a file.
 */
export interface DocumentPreviewParams {
  estate_sell_id?: number
  promotion_id?: number | null
  discount?: number | null
  locale?: 'ru' | 'en'
}

export interface CreateDocumentTemplateRequest {
  name: LocalizedText
  description?: LocalizedText | null
  type: DocumentTemplateType
  config?: DocumentTemplateConfigDto
}

export interface UpdateDocumentTemplateRequest {
  name?: LocalizedText
  description?: LocalizedText | null
  config?: DocumentTemplateConfigDto
  /** Admin / superadmin only. */
  is_published?: boolean
}

/** Download file format for `downloadGenerated`. */
export type GeneratedDocumentFormat = 'pdf' | 'docx'

// ─── Word (docx) source-file + placeholder mapping (M5/M6) ──────────────────

/**
 * 200 response from `POST /api/documents/{id}/source-file`
 * (`DocumentController::uploadSourceFile`). The uploaded `.docx` is stored under
 * a deterministic per-template path and echoed back as `source_path`.
 */
export interface UploadDocumentSourceResponseDto {
  message: string
  source_path: string
}

/**
 * 200 response from `GET /api/documents/{id}/placeholders`
 * (`DocumentController::placeholders`). The bare `${...}` token names declared
 * in the uploaded docx source (without the `${}` wrapper, e.g. `estate_price`).
 * 422 when no source file has been uploaded yet.
 */
export interface DocumentPlaceholdersResponseDto {
  placeholders: string[]
}

/**
 * A render filter that can be appended to a placeholder (`${key|filter}`):
 *   - `words` / `rouble` — money formatting (amount in words / "руб." suffix)
 *   - `format` — thousands grouping
 *   - `date` / `date_words` — date formatting (numeric / spelled out)
 */
export type DocumentFieldFilter = 'words' | 'rouble' | 'format' | 'date' | 'date_words'

/**
 * One substitutable field from `GET /api/documents/field-catalog`
 * (`DocumentController::fieldCatalog`). The controller adds `group` to each
 * entry so a flattened catalog still knows its bucket. `req_*` in the
 * `branding` group is a wildcard pattern (dynamic per-company requisites), not a
 * literal key — render it as informational, never auto-map to it.
 *
 * Keys are canonical with dots (`estate.price`, `deal.sum`, `buyer.full_name`)
 * for the MacroData groups; branding tokens stay flat (`brand_header`). The
 * placeholder a user writes in a template IS the key (`${estate.price}`); a
 * filter is appended after a pipe (`${estate.price|words}`).
 */
export interface DocumentFieldCatalogEntryDto {
  key: string
  label: LocalizedText
  group: DocumentFieldCatalogGroup
  /** Render filters applicable to this field (money → words/rouble/format, date → date/date_words). */
  filters: DocumentFieldFilter[]
  /** Sample raw value, shown in the reference modal. */
  example?: string | null
  /** Personally-identifiable data (buyer.*) — the UI flags it with a badge. */
  pii?: boolean
}

/**
 * Catalogue buckets returned by `field-catalog`. The four MacroData groups
 * (object / deal / buyer / finances) plus the render-time groups
 * (discount / common) and the per-company `branding` group.
 */
export type DocumentFieldCatalogGroup =
  | 'object'
  | 'deal'
  | 'buyer'
  | 'finances'
  | 'discount'
  | 'common'
  | 'branding'

/**
 * 200 response from `GET /api/documents/field-catalog`. Fields grouped by
 * bucket. The source of truth is `config('documents.field_catalog')`; the
 * MacroData groups are kept in lock-step with `DocumentObjectDataService`.
 * Groups may be omitted by the backend — the mapper backfills missing ones.
 */
export interface DocumentFieldCatalogResponseDto {
  groups: Partial<Record<DocumentFieldCatalogGroup, DocumentFieldCatalogEntryDto[]>>
}
